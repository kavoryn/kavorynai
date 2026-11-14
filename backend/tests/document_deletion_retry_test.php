<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentDeletionService.php';

use Kavoryn\Upload\DocumentDeletionService;
use Kavoryn\Upload\DocumentDeletionStorage;
use Kavoryn\Upload\DocumentDeletionStore;
use Kavoryn\Upload\DocumentFileException;
use Kavoryn\Upload\PrivateDocumentStorage;
use Kavoryn\Upload\DocumentDeletionUnavailableException;

function retryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class FailOnceDeletionStorage implements DocumentDeletionStorage
{
    public int $calls = 0;

    public function delete(string $storageKey): bool
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new DocumentFileException('storage_delete_failed', 'Simulated transient unlink failure.');
        }
        return true;
    }
}

final class RetryableDeletionStore implements DocumentDeletionStore
{
    public int $calls = 0;

    public function markDeletedAndGetStorageKey(string $documentId, string $userId): ?string
    {
        $this->calls++;
        return '0123456789abcdef0123456789abcdef.pdf';
    }
}

$root = sys_get_temp_dir() . '/kavoryn-delete-retry-' . bin2hex(random_bytes(6));
$public = $root . '/public';
$private = $root . '/private';
mkdir($public, 0700, true);
mkdir($private, 0700, true);
$key = '0123456789abcdef0123456789abcdef.pdf';
file_put_contents($private . '/' . $key, 'private bytes');

$store = new RetryableDeletionStore();
$documentId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
$userId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';

// Simulate a post-tombstone storage failure: the first storage root is unsafe.
// The second request uses the corrected private root and must finish cleanup.
$first = new DocumentDeletionService($store, new PrivateDocumentStorage($public . '/inside-web-root', $public));
$failed = false;
try {
    $first->delete($documentId, $userId);
} catch (DocumentDeletionUnavailableException) {
    $failed = true;
}
retryExpect($failed, 'Unsafe storage must surface as retryable cleanup failure.');
retryExpect(is_file($private . '/' . $key), 'First failed cleanup must not remove the source file.');

$retry = new DocumentDeletionService($store, new PrivateDocumentStorage($private, $public));
retryExpect($retry->delete($documentId, $userId), 'A retry must finish cleanup.');
retryExpect(!is_file($private . '/' . $key), 'Retry must remove the private file.');
retryExpect($store->calls === 2, 'Retry must re-run the idempotent owner-scoped tombstone RPC.');

// Exercise a genuine post-tombstone deletion failure at the service boundary,
// then prove a second request retries the same storage key successfully.
$transientStore = new RetryableDeletionStore();
$transientStorage = new FailOnceDeletionStorage();
$transientService = new DocumentDeletionService($transientStore, $transientStorage);
$transientFailed = false;
try {
    $transientService->delete($documentId, $userId);
} catch (DocumentDeletionUnavailableException) {
    $transientFailed = true;
}
retryExpect($transientFailed, 'A transient private-file deletion failure must be reported as retryable.');
retryExpect($transientService->delete($documentId, $userId), 'A subsequent deletion request must complete cleanup.');
retryExpect($transientStorage->calls === 2, 'Storage cleanup must be attempted again on retry.');
retryExpect($transientStore->calls === 2, 'Retry must re-fetch the tombstoned storage key.');

rmdir($private);
rmdir($public);
rmdir($root);

echo "Document deletion cleanup retry test passed." . PHP_EOL;
