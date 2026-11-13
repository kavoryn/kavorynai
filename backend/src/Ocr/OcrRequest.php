<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final readonly class OcrRequest
{
    public function __construct(
        public string $filePath,
        public string $mimeType,
        public string $language,
    ) {
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
            throw new \\InvalidArgumentException('The OCR input file must exist and be readable.');
        }

        if (!in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \\InvalidArgumentException('The OCR input media type is not supported.');
        }

        if (!in_array($language, ['en', 'ha', 'ar', 'fr'], true)) {
            throw new \\InvalidArgumentException('The requested OCR language is not supported.');
        }
    }
}
