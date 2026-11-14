<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentDeletionService.php';
require_once dirname(__DIR__) . '/src/Upload/SupabaseDocumentDeletionStore.php';

use Kavoryn\\Upload\\DocumentDeletionService;
use Kavoryn\\Upload\\DocumentDeletionStore;
use Kavoryn\\Upload\\SupabaseDocumentDeletionStore;
use Kavoryn\\Upload\\PrivateDocumentStorage;

function deletionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$documentId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
$userId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$storageKey = '0123456789abcdef0123456789abcdef.pdf';
$requests = [];
$store = new SupabaseDocumentDeletionStore(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static function (string $url, array $headers, string $body) use (&$requests, $storageKey): array {
        $requests[] = compact('url', 'headers', 'body');
        return [
            'status' => 200,
            'body' => json_encode([['stored_key' => $storageKey, 'deletion_status' => 'deleted']], JSON_THROW_ON_ERROR),
        ];
    },
);

$root = sys_get_temp_dir() . '/kavoryn-delete-' . bin2hex(random_bytes(6));
$public = $root . '/public';
$private = $root . '/private';
mkdir($public, 0700, true);
mkdir($private, 0700, true);
file_put_contents($private . '/' . $storageKey, 'private document bytes');
$storage = new PrivateDocumentStorage($private, $public);
$service = new DocumentDeletionService($store, $storage);

deletionExpect($service->delete($documentId, $userId), 'An owned document should be deleted.');
deletionExpect(!is_file($private . '/' . $storageKey), 'The private document file must be removed.');
deletionExpect(count($requests) === 1, 'Exactly one owner-scoped RPC should be made.');
deletionExpect(str_ends_with($requests[0]['url'], '/rest/v1/rpc/delete_document_for_user'), 'Deletion must use the restricted database RPC.');
deletionExpect(($requests[0]['headers']['Authorization'] ?? '') === 'Bearer service-role-jwt-test', 'The service role must remain server-side.');
$payload = json_decode($requests[0]['body'], true, 16, JSON_THROW_ON_ERROR);
deletionExpect(($payload['p_document_id'] ?? null) === $documentId, 'The RPC must receive the requested document id.');
deletionExpect(($payload['p_user_id'] ?? null) === $userId, 'The RPC must bind the authenticated user id.');

$missingStore = new SupabaseDocumentDeletionStore(
    'https://example.supabase.co', 'sb_secret_test', 'service-role-jwt-test',
    static fn (): array => ['status' => 200, 'body' => '[]'],
);
$missingService = new DocumentDeletionService($missingStore, $storage);
deletionExpect(!$missingService->delete($documentId, $userId), 'A document not owned by the user must look not found.');

$invalidRejected = false;
try {
    $service->delete('../not-a-document', $userId);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
deletionExpect($invalidRejected, 'Invalid document ids must be rejected before the RPC.');

foreach ([$private, $public, $root] as $directory) {
    if (is_dir($directory)) rmdir($directory);
}

echo "Secure document deletion tests passed (HTTP contract and private-file cleanup).\n";
