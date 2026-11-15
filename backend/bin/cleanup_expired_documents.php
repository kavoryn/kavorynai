<?php

declare(strict_types=1);

umask(0077);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorageMaintenance.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/SupabasePrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorageFactory.php';

use Kavoryn\Upload\DocumentStorageFactory;
use Kavoryn\Upload\DocumentStorageMaintenance;

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
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
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

if ($supabaseUrl === '' || parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
    $secretKey === '') {
    fwrite(STDERR, "Cleanup configuration is incomplete.\n");
    exit(1);
}

$baseUrl = rtrim($supabaseUrl, '/') . '/rest/v1/documents';
$headers = [
    'apikey' => $secretKey,
    'Accept' => 'application/json',
];
$deletedCount = 0;
$orphanedObjectsDeleted = 0;

try {
    $storage = DocumentStorageFactory::fromEnvironment(dirname(__DIR__) . '/public');
    if (!$storage instanceof DocumentStorageMaintenance) {
        throw new RuntimeException('Configured storage driver does not support safe cleanup.');
    }

    do {
        $cutoff = rawurlencode((new DateTimeImmutable('now'))->format(DATE_ATOM));
        $query = '?select=id,storage_key&expires_at=lt.' . $cutoff .
            '&status=in.(uploaded,queued,processing,succeeded,failed)&deleted_at=is.null&limit=100';
        $response = cleanupRequest('GET', $baseUrl . $query, $headers);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Could not list expired document records.');
        }

        $records = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($records) || !array_is_list($records)) {
            throw new RuntimeException('Invalid cleanup response.');
        }

        foreach ($records as $record) {
            if (!is_array($record) ||
                !is_string($record['id'] ?? null) ||
                !is_string($record['storage_key'] ?? null) ||
                !preg_match('/\A[0-9a-f-]{36}\z/i', $record['id'])) {
                throw new RuntimeException('Invalid expired document record.');
            }

            // Delete the durable private object first. If the database update
            // then fails, the next cleanup run safely retries the missing object.
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

    // Build the current reference set after expired document rows have been
    // marked deleted. Keep just-deleted object keys in this run's set to avoid
    // trying to delete them a second time during orphan cleanup.
    $referenced = [];
    $offset = 0;
    do {
        $query = '?select=storage_key&status=neq.deleted&deleted_at=is.null&limit=1000&offset=' . $offset;
        $response = cleanupRequest('GET', $baseUrl . $query, $headers);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Could not list registered document keys.');
        }
        $rows = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('Invalid registered document key response.');
        }
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['storage_key'] ?? null)) {
                $referenced[$row['storage_key']] = true;
            }
        }
        $count = count($rows);
        $offset += $count;
    } while ($count === 1000);

    $orphanedObjectsDeleted = $storage->deleteUnreferencedOlderThan(
        array_keys($referenced),
        time() - 3600,
    );

    // Purge encrypted OCR output after its document retention window ends.
    $ocrResultCleanup = cleanupRequest(
        'POST',
        rtrim($supabaseUrl, '/') . '/rest/v1/rpc/purge_expired_document_ocr_results',
        $headers + ['Content-Type' => 'application/json'],
        '{}',
    );
    if ($ocrResultCleanup['status'] < 200 || $ocrResultCleanup['status'] >= 300) {
        throw new RuntimeException('Could not purge expired OCR results.');
    }
    $ocrResultsDeleted = json_decode($ocrResultCleanup['body'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_int($ocrResultsDeleted)) {
        throw new RuntimeException('Invalid OCR result cleanup response.');
    }

    // Prune old rate-limit buckets in the same trusted maintenance job.
    $rateLimitCleanup = cleanupRequest(
        'POST',
        rtrim($supabaseUrl, '/') . '/rest/v1/rpc/cleanup_document_upload_rate_limits',
        $headers + ['Content-Type' => 'application/json'],
        '{}',
    );
    if ($rateLimitCleanup['status'] < 200 || $rateLimitCleanup['status'] >= 300) {
        throw new RuntimeException('Could not clean expired upload rate-limit counters.');
    }
    $rateLimitDeleted = json_decode($rateLimitCleanup['body'], true, 16, JSON_THROW_ON_ERROR);
    if (!is_int($rateLimitDeleted)) {
        throw new RuntimeException('Invalid rate-limit cleanup response.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Document cleanup failed: " . get_class($error) . PHP_EOL);
    exit(1);
}

echo 'Expired documents deleted: ' . $deletedCount .
    '; orphaned private objects deleted: ' . $orphanedObjectsDeleted .
    '; expired OCR results deleted: ' . $ocrResultsDeleted .
    '; expired rate-limit buckets deleted: ' . $rateLimitDeleted . PHP_EOL;
