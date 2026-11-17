<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ProviderStreamException extends \RuntimeException
{
    /** @param array<string, int|string> $diagnostics */
    public function __construct(string $message, private readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, int|string> Safe, allow-listed diagnostic fields only. */
    public function diagnostics(): array
    {
        $safe = [];
        $category = $this->diagnostics['category'] ?? null;
        if (is_string($category) && preg_match('/^[a-z0-9_]{1,48}$/D', $category) === 1) {
            $safe['category'] = $category;
        }
        foreach (['http_status', 'curl_errno'] as $key) {
            if (isset($this->diagnostics[$key]) && is_int($this->diagnostics[$key])
                && $this->diagnostics[$key] >= 0 && $this->diagnostics[$key] <= 999999) {
                $safe[$key] = $this->diagnostics[$key];
            }
        }
        $reason = $this->diagnostics['transport_reason'] ?? null;
        if (is_string($reason) && preg_match('/^[a-z0-9_]{1,32}$/D', $reason) === 1) {
            $safe['transport_reason'] = $reason;
        }

        return $safe;
    }
}
