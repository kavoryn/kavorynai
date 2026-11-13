<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/SupabaseAiProcessingConsentRecorder.php';

use Kavoryn\Ai\AiConsentStorageUnavailableException;
use Kavoryn\Ai\SupabaseAiProcessingConsentRecorder;

function aiConsentExpect(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$userId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$documentId = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a13';
$mock = function (string $url, array $headers, string $body) use ($userId, $documentId): array {
    aiConsentExpect(str_ends_with($url, '/rest/v1/rpc/record_ai_processing_consent'), 'Wrong consent RPC.');
    aiConsentExpect(($headers['Authorization'] ?? '') === 'Bearer service-role-test', 'Service role bearer missing.');
    $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    aiConsentExpect(($payload['p_user_id'] ?? null) === $userId, 'Consent must bind the authenticated user.');
    aiConsentExpect(($payload['p_document_id'] ?? null) === $documentId, 'Consent must bind the exact document.');
    aiConsentExpect(($payload['p_provider_name'] ?? null) === 'gemini', 'Consent must bind selected AI provider.');
    aiConsentExpect(($payload['p_output_language'] ?? null) === 'ha', 'Consent must bind output language.');
    return ['status' => 200, 'body' => json_encode([[
        'consent_id' => $payload['p_id'],
        'document_sha256' => hash('sha256', 'document'),
        'consented_at' => '2026-11-13T17:00:00Z',
    ]], JSON_THROW_ON_ERROR)];
};

$recorder = new \Kavoryn\Ai\SupabaseAiProcessingConsentRecorder(
    'https://example.supabase.co',
    'secret-test',
    'service-role-test',
    $mock,
);
$recorder->record($userId, $documentId, 'gemini', 'ha');

$failed = new SupabaseAiProcessingConsentRecorder(
    'https://example.supabase.co',
    'secret-test',
    'service-role-test',
    static fn (): array => ['status' => 500, 'body' => '{}'],
);
try {
    $failed->record($userId, $documentId, 'openai', 'en');
    throw new RuntimeException('Failed consent persistence must stop AI processing.');
} catch (\Kavoryn\Ai\AiConsentStorageUnavailableException) {
    // Expected fail-closed behavior.
}

echo "AI processing consent recorder tests passed." . PHP_EOL;
