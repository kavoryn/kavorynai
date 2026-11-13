<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final readonly class InspectedDocument
{
    public function __construct(
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $sha256,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $pageCount = null,
    ) {}
}
