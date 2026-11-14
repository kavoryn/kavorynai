<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class SupabaseDocumentDeletionStore implements DocumentDeletionStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '') {
            throw new DocumentDeletionUnavailableException('Document deletion is not configured.');
        }
    }

    public function markDeletedAndGetStorageKey(string $documentId, string $userId): ?string
    {
        foreach ([$documentId, $userId] as $id) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
                throw new \InvalidArgumentException('Invalid document deletion identity.');
            }
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/delete_document_for_user';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $body = json_encode([
                'p_document_id' => $documentId,
                'p_user_id' => $userId,
            ], JSON_THROW_ON_ERROR);
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new DocumentDeletionUnavailableException();
                $curl = curl_init($url);
                if ($curl === false) throw new DocumentDeletionUnavailableException();
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $errno !== 0) throw new DocumentDeletionUnavailableException();
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (DocumentDeletionUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new DocumentDeletionUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new DocumentDeletionUnavailableException();
        }

        try {
            $rows = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DocumentDeletionUnavailableException();
        }

        if (!is_array($rows) || !array_is_list($rows)) {
            throw new DocumentDeletionUnavailableException();
        }
        if ($rows === []) return null;

        $row = $rows[0] ?? null;
        if (!is_array($row) ||
            !is_string($row['stored_key'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $row['stored_key']) ||
            ($row['deletion_status'] ?? null) !== 'deleted') {
            throw new DocumentDeletionUnavailableException();
        }

        return $row['stored_key'];
    }
}
