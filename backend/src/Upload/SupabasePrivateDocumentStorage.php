<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class SupabasePrivateDocumentStorage implements DocumentStorage, DocumentStorageMaintenance
{
    private const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;
    private const LIST_PAGE_SIZE = 1000;

    /**
     * @param callable(string,string,array<string,string>,?string):array{status:int,body:string}|null $http
     */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly string $bucket,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            !is_string(parse_url($supabaseUrl, PHP_URL_HOST)) ||
            $secretKey === '' ||
            $serviceRoleJwt === '' ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{1,62}\z/', $bucket) !== 1) {
            throw new DocumentFileException(
                'storage_not_configured',
                'Private document storage is not configured correctly.',
            );
        }

        $this->assertPrivateBucketExists();
    }

    public function store(string $sourcePath, InspectedDocument $document): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new DocumentFileException('invalid_file', 'The uploaded file is unavailable.');
        }
        if ($document->sizeBytes < 1 || $document->sizeBytes > self::MAX_DOCUMENT_BYTES ||
            !in_array($document->mimeType, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ], true) ||
            !in_array($document->extension, ['pdf', 'jpg', 'png', 'webp'], true)) {
            throw new DocumentFileException('invalid_file', 'The document type or size is not supported.');
        }

        $actualSize = filesize($sourcePath);
        if (!is_int($actualSize) || $actualSize !== $document->sizeBytes) {
            throw new DocumentFileException('file_changed', 'The uploaded file changed during storage.');
        }

        $bytes = @file_get_contents($sourcePath);
        if (!is_string($bytes) || strlen($bytes) !== $document->sizeBytes ||
            !hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new DocumentFileException('file_changed', 'The uploaded file changed during storage.');
        }

        $key = bin2hex(random_bytes(16)) . '.' . $document->extension;
        $response = $this->request(
            'POST',
            $this->objectUrl($key),
            $bytes,
            [
                'Content-Type' => $document->mimeType,
                'Cache-Control' => 'private, max-age=0, no-store',
                'x-upsert' => 'false',
            ],
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new DocumentFileException(
                'storage_unavailable',
                'The private document could not be stored.',
            );
        }

        return $key;
    }

    public function copyToLocalFile(string $storageKey, string $destinationPath): void
    {
        $this->assertStorageKey($storageKey);
        if ($destinationPath === '' || !str_starts_with($destinationPath, DIRECTORY_SEPARATOR)) {
            throw new DocumentFileException('storage_write_failed', 'The temporary document path is invalid.');
        }

        $response = $this->request('GET', $this->objectUrl($storageKey), null, []);
        if ($response['status'] === 404) {
            throw new DocumentFileException('document_file_unavailable', 'The private document is unavailable.');
        }
        if ($response['status'] < 200 || $response['status'] >= 300 ||
            $response['body'] === '' || strlen($response['body']) > self::MAX_DOCUMENT_BYTES) {
            throw new DocumentFileException('storage_unavailable', 'The private document could not be retrieved.');
        }

        $output = @fopen($destinationPath, 'x+b');
        if ($output === false) {
            throw new DocumentFileException('storage_unavailable', 'The temporary document could not be created.');
        }

        if (!@chmod($destinationPath, 0600)) {
            fclose($output);
            @unlink($destinationPath);
            throw new DocumentFileException(
                'storage_permissions_failed',
                'Temporary document permissions could not be secured.',
            );
        }

        try {
            $length = strlen($response['body']);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($output, substr($response['body'], $offset));
                if ($written === false || $written === 0) {
                    throw new DocumentFileException(
                        'storage_write_failed',
                        'The private document could not be materialized safely.',
                    );
                }
                $offset += $written;
            }
            if (!fflush($output) || $offset !== $length) {
                throw new DocumentFileException(
                    'storage_write_failed',
                    'The private document could not be materialized safely.',
                );
            }
        } catch (\Throwable $error) {
            fclose($output);
            @unlink($destinationPath);
            if ($error instanceof DocumentFileException) throw $error;
            throw new DocumentFileException(
                'storage_write_failed',
                'The private document could not be materialized safely.',
            );
        }

        fclose($output);
    }

    public function delete(string $storageKey): bool
    {
        $this->assertStorageKey($storageKey);
        $response = $this->request(
            'DELETE',
            $this->bucketUrl(),
            json_encode(['prefixes' => [$storageKey]], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json'],
        );

        if ($response['status'] === 404 ||
            ($response['status'] >= 200 && $response['status'] < 300)) {
            return true;
        }

        throw new DocumentFileException(
            'storage_delete_failed',
            'The private document could not be deleted.',
        );
    }

    public function deleteUnreferencedOlderThan(array $referencedStorageKeys, int $olderThanEpoch): int
    {
        if ($olderThanEpoch < 1) {
            throw new \InvalidArgumentException('A valid orphan cleanup cutoff is required.');
        }

        $referenced = [];
        foreach ($referencedStorageKeys as $key) {
            if (!is_string($key)) continue;
            $this->assertStorageKey($key);
            $referenced[$key] = true;
        }

        $candidates = [];
        $offset = 0;
        do {
            $response = $this->request(
                'POST',
                $this->listUrl(),
                json_encode([
                    'prefix' => '',
                    'limit' => self::LIST_PAGE_SIZE,
                    'offset' => $offset,
                    'sortBy' => ['column' => 'created_at', 'order' => 'asc'],
                ], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
            );

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new DocumentFileException(
                    'storage_unavailable',
                    'Private document storage could not be listed for cleanup.',
                );
            }

            try {
                $objects = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new DocumentFileException(
                    'storage_unavailable',
                    'Private document storage returned an invalid listing.',
                );
            }
            if (!is_array($objects) || !array_is_list($objects)) {
                throw new DocumentFileException(
                    'storage_unavailable',
                    'Private document storage returned an invalid listing.',
                );
            }

            foreach ($objects as $object) {
                if (!is_array($object) || !is_string($object['name'] ?? null)) continue;
                $key = $object['name'];
                if (preg_match('/\A[a-f0-9]{32}\.(pdf|jpg|png|webp)\z/', $key) !== 1 ||
                    isset($referenced[$key])) {
                    continue;
                }

                $createdAt = $object['created_at'] ?? $object['updated_at'] ?? null;
                if (!is_string($createdAt)) continue;
                $createdEpoch = strtotime($createdAt);
                if ($createdEpoch !== false && $createdEpoch < $olderThanEpoch) {
                    $candidates[$key] = true;
                }
            }

            $count = count($objects);
            $offset += $count;
        } while ($count === self::LIST_PAGE_SIZE);

        $keys = array_keys($candidates);
        $deleted = 0;
        foreach (array_chunk($keys, 100) as $batch) {
            $response = $this->request(
                'DELETE',
                $this->bucketUrl(),
                json_encode(['prefixes' => $batch], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
            );
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new DocumentFileException(
                    'storage_delete_failed',
                    'Orphaned private documents could not be deleted.',
                );
            }
            $deleted += count($batch);
        }

        return $deleted;
    }

    private function assertPrivateBucketExists(): void
    {
        $response = $this->request('GET', $this->baseUrl() . '/bucket/' . rawurlencode($this->bucket), null, []);
        if ($response['status'] !== 200) {
            throw new DocumentFileException(
                'storage_bucket_unavailable',
                'The configured private document bucket does not exist or is unavailable.',
            );
        }

        try {
            $bucket = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DocumentFileException(
                'storage_bucket_unavailable',
                'The configured document bucket could not be verified.',
            );
        }

        if (!is_array($bucket) || ($bucket['public'] ?? null) !== false ||
            (isset($bucket['name']) && $bucket['name'] !== $this->bucket)) {
            throw new DocumentFileException(
                'storage_bucket_not_private',
                'The document bucket must already exist and be private.',
            );
        }
    }

    private function assertStorageKey(string $storageKey): void
    {
        if (preg_match('/\A[a-f0-9]{32}\.(pdf|jpg|png|webp)\z/', $storageKey) !== 1) {
            throw new DocumentFileException('invalid_storage_key', 'The private storage key is invalid.');
        }
    }

    private function baseUrl(): string
    {
        return rtrim($this->supabaseUrl, '/') . '/storage/v1';
    }

    private function bucketUrl(): string
    {
        return $this->baseUrl() . '/object/' . rawurlencode($this->bucket);
    }

    private function listUrl(): string
    {
        return $this->baseUrl() . '/object/list/' . rawurlencode($this->bucket);
    }

    private function objectUrl(string $storageKey): string
    {
        $this->assertStorageKey($storageKey);
        return $this->bucketUrl() . '/' . rawurlencode($storageKey);
    }

    /**
     * @param array<string,string> $extraHeaders
     * @return array{status:int,body:string}
     */
    private function request(
        string $method,
        string $url,
        ?string $body,
        array $extraHeaders,
    ): array {
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Accept' => 'application/json',
        ] + $extraHeaders;

        try {
            if ($this->http !== null) {
                $response = ($this->http)($method, $url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) {
                    throw new \RuntimeException('PHP cURL is required.');
                }

                $curl = curl_init($url);
                if ($curl === false) {
                    throw new \RuntimeException('Could not initialize storage request.');
                }

                $headerLines = [];
                foreach ($headers as $name => $value) {
                    $headerLines[] = $name . ': ' . $value;
                }

                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ];
                if ($body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $body;
                }
                curl_setopt_array($curl, $options);

                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);

                if ($responseBody === false || $error !== 0) {
                    throw new \RuntimeException('Private storage request failed.');
                }
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (\Throwable) {
            throw new DocumentFileException(
                'storage_unavailable',
                'Private document storage is temporarily unavailable.',
            );
        }

        if (!is_array($response) ||
            !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            strlen($response['body']) > self::MAX_DOCUMENT_BYTES + 65536) {
            throw new DocumentFileException(
                'storage_unavailable',
                'Private document storage returned an invalid response.',
            );
        }

        return $response;
    }
}
