<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use DateTimeImmutable;

interface ConsentStore
{
    public function create(ConsentRecord $record): void;

    /**
     * Atomically consumes a matching, unexpired, unrevoked grant exactly once.
     * Implementations must perform the match and consumed-state update in one
     * database transaction/statement. A read-then-write implementation is unsafe.
     */
    public function consumeOnce(
        string $tokenHash,
        string $userId,
        string $action,
        string $payloadSha256,
        string $language,
        bool $translate,
        DateTimeImmutable $now,
    ): bool;
}
