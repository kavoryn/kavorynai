<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use DateTimeImmutable;

final readonly class ConsentGrant
{
    public function __construct(
        public string $id,
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
