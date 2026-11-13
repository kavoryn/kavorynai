<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final readonly class OcrJob
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $userId,
        public string $storageKey,
        public string $mimeType,
        public string $language,
        public bool $translate,
        public int $attempt,
        public string $workerId,
    ) {
        foreach (['id' => $id, 'documentId' => $documentId, 'userId' => $userId] as $name => $value) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
                throw new \InvalidArgumentException('Invalid OCR job identifier: ' . $name);
            }
        }
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $storageKey)) {
            throw new \InvalidArgumentException('Invalid OCR storage key.');
        }
        if (!in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) ||
            !in_array($language, ['en', 'ha', 'ar', 'fr'], true) ||
            $attempt < 1 || $attempt > 3 ||
            !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $workerId)) {
            throw new \InvalidArgumentException('Invalid OCR job metadata.');
        }
    }
}
