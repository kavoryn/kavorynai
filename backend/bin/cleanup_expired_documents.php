<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';

use Kavoryn\Upload\PrivateDocumentStorage;

function cleanupRequest(string $method, string $url, array $headers, ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required.');
    }

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize cleanup request.');
    }

    $headerLines = [];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($curl);
    curl_close($curl);

    if ($responseBody === false || $error !== 0) {
        throw new RuntimeException('Cleanup service request failed.');
    }

    return ['status' => $status, 'body' => (string) $responseBody];
}

$supabaseUrl = getenv('SUPABASE_URL') ?: '';
$secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
$serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$storagePath = getenv('PRIVATE_DOCUMENT_STORAGE_PATH') ?: '';

if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '' || $storagePath === '') {
    fwrite(STDERR, "Cleanup configuration is incomplete.\n");
    exit(1);
}

$baseUrl = rtrim($supabaseUrl, '/') . '/rest/v1/documents';
$headers = [
    'apikey' => $secretKey,
    'Authorization' => 'Bearer ' . $serviceRoleJwt,
    'Accept' => 'application/json',
];
$storage = new PrivateDocumentStorage($storagePath, dirname(__DIR__) . '/public');
$deletedCount = 0;

try {
    do {
        $cutoff = rawurlencode((new DateTimeImmutable('now'))->format(DATE_ATOM));
        $query = '?select=id,storage_key&expires_at=lt.' . $cutoff .
            '&status=in.(uploaded,failed)&deleted_at=is.null&limit=100';
        $response = cleanupRequest('GET', $baseUrl . $query, $headers);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Could not list expired document records.');
        }

        $records = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            throw new RuntimeException('Invalid cleanup response.');
        }

        foreach ($records as $record) {
            if (!is_array($record) ||
                !is_string($record['id'] ?? null) ||
                !is_string($record['storage_key'] ?? null) ||
                !preg_match('/^[0-9a-f-]{36}$/i', $record['id'])) {
                throw new RuntimeException('Invalid expired document record.');
            }

            // Delete the private file first. If the database update then fails,
            // the next cron run safely retries the missing-file deletion.
            $storage->delete($record['storage_key']);
            $deletedAt = (new DateTimeImmutable('now'))->format(DATE_ATOM);
            $patchUrl = $baseUrl . '?id=eq.' . rawurlencode($record['id']);
            $patchHeaders = $headers + [
                'Content-Type' => 'application/json',
                'Prefer' => 'return=minimal',
            ];
            $patch = cleanupRequest(
                'PATCH',
                $patchUrl,
                $patchHeaders,
                json_encode([
                    'status' => 'deleted',
                    'deleted_at' => $deletedAt,
                ], JSON_THROW_ON_ERROR),
            );
            if ($patch['status'] < 200 || $patch['status'] >= 300) {
                throw new RuntimeException('Could not mark expired document as deleted.');
            }
            $deletedCount++;
        }
    } while (count($records) === 100);
} catch (Throwable $error) {
    fwrite(STDERR, "Document cleanup failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Expired documents deleted: ' . $deletedCount . PHP_EOL;
