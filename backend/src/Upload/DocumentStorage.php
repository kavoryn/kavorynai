<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

interface DocumentStorage
{
    public function store(string $sourcePath, InspectedDocument $document): string;

    public function delete(string $storageKey): bool;

    /**
     * Materialize a stored object into an owner-only local file for a bounded
     * processing operation. The caller must remove the destination afterwards.
     */
    public function copyToLocalFile(string $storageKey, string $destinationPath): void;
}
