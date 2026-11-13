<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

use DateTimeImmutable;

final readonly class DocumentRegistration
{
    public function __construct(
        public string $id,
        public string $status,
        public DateTimeImmutable $createdAt,
        public string $storageKey,
    ) {}
}
