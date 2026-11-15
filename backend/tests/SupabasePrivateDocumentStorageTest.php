<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorageMaintenance.php';
require_once dirname(__DIR__) . '/src/Upload/SupabasePrivateDocumentStorage.php';

use Kavoryn\Upload\DocumentFileException;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\SupabasePrivateDocumentStorage;

function supabaseStorageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$fixture = 'private image bytes';
$source = tempnam(sys_get_temp_dir(), 'kvn-storage-source-');
if ($source === false || file_put_contents($source, $fixture) !== strlen($fixture)) {
    throw new RuntimeException('Could not create storage test fixture.');
}
$document = new InspectedDocument(
    'image/png',
    'png',
    strlen($fixture),
    hash('sha256', $fixture),
);
$oldOrphan = '11111111111111111111111111111111.png';
$recentOrphan = '22222222222222222222222222222222.pdf';
$oldReferenced = '33333333333333333333333333333333.jpg';
$uploadedKey = null;
$deletedPrefixes = [];
$objectRequests = [];

$http = static function (
    string $method,
    string $url,
    array $headers,
    ?string $body,
) use ($fixture, $oldOrphan, $recentOrphan, $oldReferenced, &$uploadedKey, &$deletedPrefixes, &$objectRequests): array {
    supabaseStorageExpect(($headers['apikey'] ?? '') === 'sb_secret_test_only', 'Storage requests must use the server-only API key.');
    supabaseStorageExpect(($headers['Authorization'] ?? '') === 'Bearer service-role-test-only', 'Storage requests must use the server-side service role JWT.');

    if ($method === 'GET' && str_ends_with($url, '/storage/v1/bucket/private-documents')) {
        return ['status' => 200, 'body' => '{"id":"private-documents","name":"private-documents","public":false}'];
    }

    if ($method === 'POST' && str_contains($url, '/storage/v1/object/private-documents/')) {
        supabaseStorageExpect(($headers['x-upsert'] ?? null) === 'false', 'Upload must not overwrite existing objects.');
        supabaseStorageExpect(($headers['Content-Type'] ?? null) === 'image/png', 'Upload must preserve verified document MIME type.');
        supabaseStorageExpect($body === $fixture, 'Upload must send the exact inspected bytes.');
        $uploadedKey = basename(parse_url($url, PHP_URL_PATH));
        $objectRequests[] = [$method, $url];
        return ['status' => 200, 'body' => '{"Key":"private-documents/' . $uploadedKey . '"}'];
    }

    if ($method === 'GET' && str_contains($url, '/storage/v1/object/private-documents/')) {
        $objectRequests[] = [$method, $url];
        return ['status' => 200, 'body' => $fixture];
    }

    if ($method === 'DELETE' && str_ends_with($url, '/storage/v1/object/private-documents')) {
        $payload = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        supabaseStorageExpect(is_array($payload['prefixes'] ?? null), 'Delete must use the Storage bulk remove API.');
        $deletedPrefixes = array_merge($deletedPrefixes, $payload['prefixes']);
        $objectRequests[] = [$method, $url];
        return ['status' => 200, 'body' => json_encode(array_map(
            static fn (string $name): array => ['name' => $name],
            $payload['prefixes'],
        ), JSON_THROW_ON_ERROR)];
    }

    if ($method === 'POST' && str_ends_with($url, '/storage/v1/object/list/private-documents')) {
        $payload = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        supabaseStorageExpect(($payload['prefix'] ?? null) === '', 'Orphan cleanup must list only the configured bucket root.');
        $objectRequests[] = [$method, $url];
        return [
            'status' => 200,
            'body' => json_encode([
                ['name' => $oldOrphan, 'created_at' => '2026-01-01T00:00:00Z'],
                ['name' => $recentOrphan, 'created_at' => '2026-11-15T12:00:00Z'],
                ['name' => $oldReferenced, 'created_at' => '2026-01-02T00:00:00Z'],
                ['name' => '../not-a-storage-key', 'created_at' => '2026-01-01T00:00:00Z'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    throw new RuntimeException('Unexpected Storage API call: ' . $method . ' ' . $url);
};

try {
    $storage = new SupabasePrivateDocumentStorage(
        'https://example.supabase.co',
        'sb_secret_test_only',
        'service-role-test-only',
        'private-documents',
        $http,
    );

    $key = $storage->store($source, $document);
    supabaseStorageExpect(is_string($key) && preg_match('/\A[a-f0-9]{32}\.png\z/', $key) === 1, 'Upload must return a random opaque storage key.');
    supabaseStorageExpect($key === $uploadedKey, 'Upload response must correspond to the stored object key.');

    $destination = sys_get_temp_dir() . '/kvn-storage-download-' . bin2hex(random_bytes(8));
    $storage->copyToLocalFile($key, $destination);
    supabaseStorageExpect(file_get_contents($destination) === $fixture, 'Private object download must materialize exact bytes.');
    supabaseStorageExpect((fileperms($destination) & 0777) === 0600, 'Downloaded temporary document must be owner-only.');
    unlink($destination);

    supabaseStorageExpect($storage->delete($key), 'Deleting a valid private object must succeed.');
    supabaseStorageExpect(in_array($key, $deletedPrefixes, true), 'Delete must send the exact private object key.');

    $deletedPrefixes = [];
    $deleted = $storage->deleteUnreferencedOlderThan([$oldReferenced], strtotime('2026-11-01T00:00:00Z'));
    supabaseStorageExpect($deleted === 1, 'Cleanup must delete only old orphan objects.');
    supabaseStorageExpect($deletedPrefixes === [$oldOrphan], 'Cleanup must preserve recent and referenced objects.');

    $before = count($objectRequests);
    $invalidRejected = false;
    try {
        $storage->delete('../private-document.png');
    } catch (DocumentFileException $error) {
        $invalidRejected = $error->errorCode === 'invalid_storage_key';
    }
    supabaseStorageExpect($invalidRejected, 'Invalid keys must be rejected before any Storage API request.');
    supabaseStorageExpect(count($objectRequests) === $before, 'Invalid key must not call the Storage API.');
} finally {
    @unlink($source);
}

$publicBucketRejected = false;
try {
    new SupabasePrivateDocumentStorage(
        'https://example.supabase.co',
        'sb_secret_test_only',
        'service-role-test-only',
        'public-documents',
        static fn (): array => ['status' => 200, 'body' => '{"id":"public-documents","name":"public-documents","public":true}'],
    );
} catch (DocumentFileException $error) {
    $publicBucketRejected = $error->errorCode === 'storage_bucket_not_private';
}
supabaseStorageExpect($publicBucketRejected, 'Public buckets must be refused.');

$missingBucketRejected = false;
try {
    new SupabasePrivateDocumentStorage(
        'https://example.supabase.co',
        'sb_secret_test_only',
        'service-role-test-only',
        'not-created',
        static fn (): array => ['status' => 404, 'body' => '{"message":"Not found"}'],
    );
} catch (DocumentFileException $error) {
    $missingBucketRejected = $error->errorCode === 'storage_bucket_unavailable';
}
supabaseStorageExpect($missingBucketRejected, 'Missing buckets must fail closed and must not be created.');

echo "Supabase private document storage tests passed (private bucket verification, non-upsert upload, secure download, deletion, orphan cleanup, and fail-closed bucket handling)." . PHP_EOL;
