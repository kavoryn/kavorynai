<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentDeletionService.php';
require_once dirname(__DIR__) . '/src/Upload/SupabaseDocumentDeletionStore.php';

use Kavoryn\Upload\DocumentDeletionService;
use Kavoryn\Upload\DocumentDeletionUnavailableException;
use Kavoryn\Upload\DocumentFileException;
use Kavoryn\Upload\DocumentStorage;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\SupabaseDocumentDeletionStore;

function deletionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class TestDocumentStorage implements DocumentStorage
{
    /** @var list<string> */
    public array $deletedKeys = [];
    public bool $failDelete = false;

    public function store(string $sourcePath, InspectedDocument $document): string
    {
        throw new LogicException('Not used by deletion tests.');
    }

    public function delete(string $storageKey): bool
    {
        $this->deletedKeys[] = $storageKey;
        if ($this->failDelete) {
            throw new DocumentFileException('storage_delete_failed', 'simulated private-object delete failure');
        }
        return true;
    }

    public function copyToLocalFile(string $storageKey, string $destinationPath): void
    {
        throw new LogicException('Not used by deletion tests.');
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

$storage = new TestDocumentStorage();
$service = new DocumentDeletionService($store, $storage);
deletionExpect($service->delete($documentId, $userId), 'An owned document should be deleted.');
deletionExpect($storage->deletedKeys === [$storageKey], 'The configured storage adapter must delete the private object.');
deletionExpect(count($requests) === 1, 'Exactly one owner-scoped RPC should be made.');
deletionExpect(str_ends_with($requests[0]['url'], '/rest/v1/rpc/delete_document_for_user'), 'Deletion must use the restricted database RPC.');
deletionExpect(!isset($requests[0]['headers']['Authorization']) && isset($requests[0]['headers']['apikey']), 'Privileged Supabase calls must use the secret API key via apikey only.');
$payload = json_decode($requests[0]['body'], true, 16, JSON_THROW_ON_ERROR);
deletionExpect(($payload['p_document_id'] ?? null) === $documentId, 'The RPC must receive the requested document id.');
deletionExpect(($payload['p_user_id'] ?? null) === $userId, 'The RPC must bind the authenticated user id.');

$missingStorage = new TestDocumentStorage();
$missingStore = new SupabaseDocumentDeletionStore(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static fn (): array => ['status' => 200, 'body' => '[]'],
);
$missingService = new DocumentDeletionService($missingStore, $missingStorage);
deletionExpect(!$missingService->delete($documentId, $userId), 'A document not owned by the user must look not found.');
deletionExpect($missingStorage->deletedKeys === [], 'A missing or other-owner document must not delete any storage object.');

$retryStorage = new TestDocumentStorage();
$retryStorage->failDelete = true;
$retryService = new DocumentDeletionService($store, $retryStorage);
$retryFailed = false;
try {
    $retryService->delete($documentId, $userId);
} catch (DocumentDeletionUnavailableException) {
    $retryFailed = true;
}
deletionExpect($retryFailed, 'A failed private-object deletion must produce a retryable unavailable response.');
$retryStorage->failDelete = false;
deletionExpect($retryService->delete($documentId, $userId), 'A repeat DELETE must finish cleanup after a transient storage failure.');
deletionExpect(count($retryStorage->deletedKeys) === 2, 'A retry must attempt physical object cleanup again.');

$invalidRejected = false;
try {
    $service->delete('../not-a-document', $userId);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
deletionExpect($invalidRejected, 'Invalid document ids must be rejected before the RPC.');

echo "Secure document deletion tests passed (owner-scoped RPC, storage interface and retry contract).\n";
