<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

interface DocumentDeletionStore
{
    public function markDeletedAndGetStorageKey(string $documentId, string $userId): ?string;
}

final class DocumentDeletionUnavailableException extends \RuntimeException {}

final class DocumentDeletionService
{
    public function __construct(
        private readonly DocumentDeletionStore $store,
        private readonly PrivateDocumentStorage $storage,
    ) {}

    public function delete(string $documentId, string $userId): bool
    {
        foreach ([$documentId, $userId] as $id) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
                throw new \InvalidArgumentException('Invalid document deletion identity.');
            }
        }

        $storageKey = $this->store->markDeletedAndGetStorageKey($documentId, $userId);
        if ($storageKey === null) {
            return false;
        }

        try {
            // The database tombstone and OCR purge happen first. If filesystem
            // cleanup fails, the document stays inaccessible and a retry can
            // safely finish removing the same private file.
            $this->storage->delete($storageKey);
        } catch (DocumentFileException) {
            throw new DocumentDeletionUnavailableException('Private document cleanup is pending.');
        }

        return true;
    }
}
