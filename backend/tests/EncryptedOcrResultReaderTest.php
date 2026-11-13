<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultReader.php';

use Kavoryn\Ocr\OcrResultNotFoundException;
use Kavoryn\Ocr\OcrResultReaderUnavailableException;
use Kavoryn\Ocr\SupabaseEncryptedOcrResultReader;

function expectReader(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$plain = json_encode([
    'text' => "Ƙarɓi ɗalibi",
    'uncertain_spans' => [
        ['start' => 0, 'end' => 5, 'confidence' => 0.42, 'reason' => 'low_confidence'],
    ],
    'detected_language' => 'ha',
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox($plain, $nonce, $key);
$sha = hash_hmac('sha256', $plain, $key);
$documentId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$userId = 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12';
$seen = null;

$http = function (string $url, array $headers, string $body) use (&$seen, $documentId, $userId, $ciphertext, $nonce, $sha): array {
    $seen = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
    expectReader(($seen['body']['p_document_id'] ?? null) === $documentId, 'Document ID must be passed to the owner-checked RPC.');
    expectReader(($seen['body']['p_user_id'] ?? null) === $userId, 'Authenticated user ID must be passed to the owner-checked RPC.');
    return ['status' => 200, 'body' => json_encode([[
        'ciphertext_base64' => base64_encode($ciphertext),
        'nonce_base64' => base64_encode($nonce),
        'encryption_key_version' => '1',
        'result_sha256' => $sha,
        'detected_language' => 'ha',
        'character_count' => 12,
        'uncertain_span_count' => 1,
        'expires_at' => (new DateTimeImmutable('+30 minutes'))->format(DATE_ATOM),
    ]], JSON_THROW_ON_ERROR)];
};

$reader = new SupabaseEncryptedOcrResultReader(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-test',
    $key,
    $http,
);
$result = $reader->read($documentId, $userId);
expectReader($result->text === "Ƙarɓi ɗalibi", 'Reader must decrypt the original OCR text.');
expectReader($result->detectedLanguage === 'ha', 'Reader must preserve detected language.');
expectReader(count($result->uncertainSpans) === 1, 'Reader must preserve uncertain word markers.');
expectReader(str_ends_with($seen['url'], '/rest/v1/rpc/get_document_ocr_result'), 'Reader must use the restricted RPC.');
expectReader(($seen['headers']['Authorization'] ?? '') === 'Bearer service-role-test', 'Reader must use server-only service credentials.');

$badHttp = function (): array {
    return ['status' => 200, 'body' => '[]'];
};
$missing = new SupabaseEncryptedOcrResultReader(
    'https://example.supabase.co', 'sb_secret_test', 'service-role-test', $key, $badHttp
);
try {
    $missing->read($documentId, $userId);
    expectReader(false, 'Missing OCR results must be reported as not found.');
} catch (OcrResultNotFoundException) {
    // Expected.
}

$tamperedHttp = function (string $url, array $headers, string $body) use ($ciphertext, $nonce): array {
    return ['status' => 200, 'body' => json_encode([[
        'ciphertext_base64' => base64_encode($ciphertext),
        'nonce_base64' => base64_encode($nonce),
        'encryption_key_version' => '1',
        'result_sha256' => str_repeat('0', 64),
        'detected_language' => 'ha',
        'character_count' => 12,
        'uncertain_span_count' => 1,
        'expires_at' => (new DateTimeImmutable('+30 minutes'))->format(DATE_ATOM),
    ]], JSON_THROW_ON_ERROR)];
};
$tampered = new SupabaseEncryptedOcrResultReader(
    'https://example.supabase.co', 'sb_secret_test', 'service-role-test', $key, $tamperedHttp
);
try {
    $tampered->read($documentId, $userId);
    expectReader(false, 'Tampered result HMAC must be rejected.');
} catch (OcrResultReaderUnavailableException) {
    // Expected.
}

echo "Encrypted OCR result reader tests passed." . PHP_EOL;
