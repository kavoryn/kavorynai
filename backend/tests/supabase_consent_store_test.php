<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Consent/ConsentRecord.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentGrant.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentStore.php';
require_once dirname(__DIR__) . '/src/Consent/ConsentStorageUnavailableException.php';
require_once dirname(__DIR__) . '/src/Consent/SupabaseConsentStore.php';

use Kavoryn\Consent\ConsentRecord;
use Kavoryn\Consent\SupabaseConsentStore;

function storeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$requests = [];
$store = new SupabaseConsentStore(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static function (string $url, array $headers, ?string $body) use (&$requests): array {
        $requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if (str_ends_with($url, '/rpc/consume_processing_consent')) {
            return ['status' => 200, 'body' => 'true'];
        }
        return ['status' => 201, 'body' => ''];
    },
);

$now = new DateTimeImmutable('2026-11-13T12:00:00+00:00');
$record = new ConsentRecord(
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'document.process',
    hash('sha256', 'sample document'),
    'ha',
    false,
    hash('sha256', 'opaque token'),
    $now,
    $now->modify('+5 minutes'),
);

$store->create($record);
storeExpect(count($requests) === 1, 'Create must issue exactly one database request.');
$insert = json_decode($requests[0]['body'] ?? '', true, 16, JSON_THROW_ON_ERROR);
storeExpect(($requests[0]['url'] ?? '') === 'https://example.supabase.co/rest/v1/processing_consents', 'Create must use the consent table endpoint.');
storeExpect(($requests[0]['headers']['apikey'] ?? '') === 'sb_secret_test', 'New secret API key must be sent in apikey.');
storeExpect(($requests[0]['headers']['Authorization'] ?? '') === 'Bearer service-role-jwt-test', 'Legacy service-role JWT must be used as bearer.');
storeExpect(($insert['id'] ?? null) === $record->id, 'Persisted consent ID must match the API grant ID.');
storeExpect(($insert['confirmation_token_hash'] ?? null) === $record->tokenHash, 'Only the token hash must be persisted.');
storeExpect(($insert['provider_name'] ?? 'missing') === null, 'Provider selection can remain unset at consent time.');

$consumed = $store->consumeOnce(
    $record->tokenHash,
    $record->userId,
    $record->action,
    $record->payloadSha256,
    $record->language,
    $record->translate,
    $now,
);
storeExpect($consumed, 'Store must return the atomic consume result.');
storeExpect(str_ends_with($requests[1]['url'], '/rest/v1/rpc/consume_processing_consent'), 'Consume must use the atomic database RPC.');
$rpc = json_decode($requests[1]['body'] ?? '', true, 16, JSON_THROW_ON_ERROR);
storeExpect(($rpc['p_user_id'] ?? null) === $record->userId, 'Consume must bind the user ID.');
storeExpect(($rpc['p_payload_sha256'] ?? null) === $record->payloadSha256, 'Consume must bind the payload digest.');

echo "Supabase consent adapter tests passed (HTTP contract stub)." . PHP_EOL;
