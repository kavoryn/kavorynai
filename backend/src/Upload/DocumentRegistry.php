<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

interface DocumentRegistry
{
    public function register(
        string $documentId,
        string $userId,
        string $consentTokenHash,
        InspectedDocument $document,
        string $language,
        bool $translate,
        string $storageKey,
        string $idempotencyKey,
    ): DocumentRegistration;
}
