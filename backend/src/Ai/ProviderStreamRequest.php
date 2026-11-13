<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ProviderStreamRequest
{
    /** @param list<string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly array $headers,
        public readonly ProviderSseEventMapper $mapper,
    ) {
        if ($url === '' || $body === '' || $headers === []) {
            throw new \InvalidArgumentException('A provider stream request is incomplete.');
        }
    }
}
