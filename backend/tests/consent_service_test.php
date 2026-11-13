<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Consent/ConsentRecord.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentGrant.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentStore.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentException.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentService.php';

use Kavoryn\Consent\ConsentException;
use Kavoryn\Consent\ConsentRecord;
use Kavoryn\Consent\ConsentService;
use Kavoryn\Consent\ConsentStore;

function consentExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class InMemoryConsentStore implements ConsentStore
{
    /** @var array<string, ConsentRecord> */
    public array $records = [];
    /** @var array<string, bool> */
    private array $consumed = [];

    public function create(ConsentRecord $record): ConsentRecord
    {
        $this->records[$record->tokenHash] = $record;
        $this->consumed[$record->tokenHash] = false;
        return $record;
    }

    public function consumeOnce(
        string $tokenHash,
        string $userId,
        string $action,
        string $payloadSha256,
        string $language,
        bool $translate,
        DateTimeImmutable $now,
    ): bool {
        $record = $this->records[$tokenHash] ?? null;
        if ($record === null || $this->consumed[$tokenHash] ||
            $record->userId !== $userId ||
            $record->action !== $action ||
            $record->payloadSha256 !== $payloadSha256 ||
            $record->language !== $language ||
            $record->translate !== $translate ||
            $record->expiresAt <= $now) {
            return false;
        }

        $this->consumed[$tokenHash] = true;
        return true;
    }
}

$now = new DateTimeImmutable('2026-11-13T12:00:00+00:00');
$store = new InMemoryConsentStore();
$service = new ConsentService($store, 300, static fn (): DateTimeImmutable => $now);
$digest = hash('sha256', 'document bytes');

$notConfirmed = false;
try {
    $service->issue('user-1', 'document.process', $digest, 'ha', false, false);
} catch (ConsentException) {
    $notConfirmed = true;
}
consentExpect($notConfirmed, 'Consent must not be issued without explicit confirmation.');

$grant = $service->issue('user-1', 'document.process', $digest, 'ha', false, true);
consentExpect(strlen($grant->token) === 43, 'Grant token must be a 32-byte URL-safe opaque token.');
consentExpect((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $grant->id), 'Consent ID must be a version-4 UUID for PostgreSQL.');
consentExpect(count($store->records) === 1, 'Store must receive a consent record.');
$record = array_values($store->records)[0];
consentExpect($record->tokenHash === hash('sha256', $grant->token), 'Only the token hash must be stored.');
consentExpect($record->expiresAt->getTimestamp() - $record->createdAt->getTimestamp() === 300, 'Grant must have the configured short expiry.');

consentExpect($service->consume($grant->token, 'user-1', 'document.process', $digest, 'ha', false), 'Matching grant must be accepted once.');
consentExpect(!$service->consume($grant->token, 'user-1', 'document.process', $digest, 'ha', false), 'Replayed grant must be rejected.');
consentExpect(!$service->consume($grant->token, 'another-user', 'document.process', $digest, 'ha', false), 'Grant must be bound to its user.');
consentExpect(!$service->consume($grant->token, 'user-1', 'document.process', hash('sha256', 'other file'), 'ha', false), 'Grant must be bound to the exact document digest.');
consentExpect(!$service->consume('not-a-token', 'user-1', 'document.process', $digest, 'ha', false), 'Malformed token must be rejected.');

echo "Consent service tests passed (in-memory contract only)." . PHP_EOL;
