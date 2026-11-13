<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use DateTimeImmutable;
use InvalidArgumentException;

final class ConsentService
{
    private const ACTION = 'document.process';
    private const LANGUAGES = ['en', 'ha', 'ar', 'fr'];

    /** @param callable(): DateTimeImmutable|null $clock */
    public function __construct(
        private readonly ConsentStore $store,
        private readonly int $ttlSeconds = 300,
        private readonly mixed $clock = null,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 600) {
            throw new InvalidArgumentException('Consent TTL must be between 60 and 600 seconds.');
        }
    }

    public function issue(
        string $userId,
        string $action,
        string $payloadSha256,
        string $language,
        bool $translate,
        bool $confirmed,
    ): ConsentGrant {
        if (!$confirmed ||
            $userId === '' ||
            $action !== self::ACTION ||
            !$this->validDigest($payloadSha256) ||
            !in_array($language, self::LANGUAGES, true)) {
            throw new ConsentException();
        }

        $now = $this->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $record = new ConsentRecord(
            $this->newUuid(),
            $userId,
            $action,
            strtolower($payloadSha256),
            $language,
            $translate,
            hash('sha256', $token),
            $now,
            $now->modify('+' . $this->ttlSeconds . ' seconds'),
        );

        $this->store->create($record);

        return new ConsentGrant($record->id, $token, $record->expiresAt);
    }

    public function consume(
        string $token,
        string $userId,
        string $action,
        string $payloadSha256,
        string $language,
        bool $translate,
    ): bool {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ||
            $userId === '' ||
            $action !== self::ACTION ||
            !$this->validDigest($payloadSha256) ||
            !in_array($language, self::LANGUAGES, true)) {
            return false;
        }

        return $this->store->consumeOnce(
            hash('sha256', $token),
            $userId,
            $action,
            strtolower($payloadSha256),
            $language,
            $translate,
            $this->now(),
        );
    }

    private function newUuid(): string
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

    private function validDigest(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $digest) === 1;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            $time = ($this->clock)();
            if (!$time instanceof DateTimeImmutable) {
                throw new InvalidArgumentException('Consent clock must return DateTimeImmutable.');
            }
            return $time;
        }

        return new DateTimeImmutable('now');
    }
}
