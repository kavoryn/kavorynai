<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistration.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistry.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentRegistryUnavailableException.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentConsentRejectedException.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentIdempotencyConflictException.php';
require_once dirname(__DIR__) . '/src/Upload/SupabaseDocumentRegistry.php';

use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\SupabaseDocumentRegistry;

function registryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$requests = [];
$registry = new SupabaseDocumentRegistry(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static function (string $url, array $headers, string $body) use (&$requests): array {
        $requests[] = compact('url', 'headers', 'body');
        $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        return [
            'status' => 200,
            'body' => json_encode([[
                'document_id' => $input['p_id'],
                'document_status' => 'uploaded',
                'registered_at' => '2026-11-13T12:00:00+00:00',
                'stored_key' => $input['p_storage_key'],
            ]], JSON_THROW_ON_ERROR),
        ];
    },
);

$document = new InspectedDocument(
    'application/pdf',
    'pdf',
    1024,
    hash('sha256', 'verified pdf bytes'),
);
$tokenHash = hash('sha256', 'opaque consent token');
$registration = $registry->register(
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    $tokenHash,
    $document,
    'ha',
    false,
    '0123456789abcdef0123456789abcdef.pdf',
    'test-idempotency-key-0001',
);

registryExpect(count($requests) === 1, 'Registry must make exactly one atomic RPC call.');
registryExpect(str_ends_with($requests[0]['url'], '/rest/v1/rpc/register_uploaded_document'), 'Registry must use the atomic document RPC.');
registryExpect(($requests[0]['headers']['apikey'] ?? '') === 'sb_secret_test', 'Server secret key must be sent in apikey.');
registryExpect(($requests[0]['headers']['Authorization'] ?? '') === 'Bearer service-role-jwt-test', 'Server role JWT must be sent only as bearer.');
$input = json_decode($requests[0]['body'], true, 16, JSON_THROW_ON_ERROR);
registryExpect(($input['p_consent_token_hash'] ?? null) === $tokenHash, 'Registry must send only the consent token hash.');
registryExpect(($input['p_user_id'] ?? null) === 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', 'Registry must bind the authenticated user.');
registryExpect(($input['p_payload_sha256'] ?? null) === $document->sha256, 'Registry must bind the server-computed file digest.');
registryExpect(($input['p_idempotency_key'] ?? null) === 'test-idempotency-key-0001', 'Registry must pass the retry key.');
registryExpect($registration->status === 'uploaded', 'Registry must return the registered status.');
registryExpect($registration->storageKey === $input['p_storage_key'], 'Registry must return the private storage key for internal cleanup only.');

$rejectedRegistry = new SupabaseDocumentRegistry(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static fn (): array => [
        'status' => 400,
        'body' => '{"message":"Consent is invalid, expired, mismatched, or already used"}',
    ],
);
$consentRejected = false;
try {
    $rejectedRegistry->register(
        'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
        $tokenHash,
        $document,
        'ha',
        false,
        '0123456789abcdef0123456789abcdef.pdf',
        'test-idempotency-key-0002',
    );
} catch (\Kavoryn\Upload\DocumentConsentRejectedException) {
    $consentRejected = true;
}
registryExpect($consentRejected, 'Database consent rejection must map to a safe client conflict.');

$conflictRegistry = new SupabaseDocumentRegistry(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-jwt-test',
    static fn (): array => [
        'status' => 400,
        'body' => '{"message":"Idempotency key conflict"}',
    ],
);
$idempotencyRejected = false;
try {
    $conflictRegistry->register(
        'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
        $tokenHash,
        $document,
        'ha',
        false,
        '0123456789abcdef0123456789abcdef.pdf',
        'test-idempotency-key-0003',
    );
} catch (\Kavoryn\Upload\DocumentIdempotencyConflictException) {
    $idempotencyRejected = true;
}
registryExpect($idempotencyRejected, 'Idempotency conflict must map to a safe client conflict.');

echo "Supabase document registry tests passed (HTTP contract stub)." . PHP_EOL;
