<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class DocumentUploadService
{
    private const LANGUAGES = ['en', 'ha', 'ar', 'fr'];

    public function __construct(
        private readonly DocumentFileInspector $inspector,
        private readonly PrivateDocumentStorage $storage,
        private readonly DocumentRegistry $registry,
    ) {}

    public function upload(
        string $userId,
        string $sourcePath,
        string $originalName,
        string $consentToken,
        string $language,
        bool $translate,
        string $idempotencyKey,
    ): DocumentRegistration {
        if ($userId === '' ||
            !preg_match('/^[A-Za-z0-9_-]{43}$/', $consentToken) ||
            !in_array($language, self::LANGUAGES, true) ||
            !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $idempotencyKey)) {
            throw new DocumentFileException('invalid_upload_request', 'The upload request is incomplete or invalid.');
        }

        $document = $this->inspector->inspect($sourcePath, $originalName);
        $storageKey = $this->storage->store($sourcePath, $document);

        // Registration atomically consumes the matching consent and inserts the
        // document row. Keep the private file if the network result is ambiguous:
        // the database may have committed before the connection was interrupted.
        try {
            $registration = $this->registry->register(
                self::newUuid(),
                $userId,
                hash('sha256', $consentToken),
                $document,
                $language,
                $translate,
                $storageKey,
                $idempotencyKey,
            );
        } catch (DocumentConsentRejectedException | DocumentIdempotencyConflictException $error) {
            $this->storage->delete($storageKey);
            throw $error;
        }

        // A retry with the same idempotency key may resolve to an earlier
        // successful upload. Remove this retry's newly staged duplicate.
        if ($registration->storageKey !== $storageKey) {
            $this->storage->delete($storageKey);
        }

        return $registration;
    }

    private static function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
