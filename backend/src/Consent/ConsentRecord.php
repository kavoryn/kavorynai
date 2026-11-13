<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use DateTimeImmutable;

final readonly class ConsentRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $action,
        public string $payloadSha256,
        public string $language,
        public bool $translate,
        public string $tokenHash,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
