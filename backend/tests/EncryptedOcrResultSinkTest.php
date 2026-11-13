<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProviderUnavailableException.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJob.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResultSink.php';
require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultSink.php';

use Kavoryn\Ocr\OcrJob;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\SupabaseEncryptedOcrResultSink;

function expectSink(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$seen = null;
$http = function (string $url, array $headers, string $body) use (&$seen): array {
    $seen = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
    return ['status' => 200, 'body' => 'true'];
};

$job = new OcrJob(
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
    'image/png',
    'ha',
    false,
    1,
    'worker-test-0001',
);
$result = new OcrResult("Ƙarɓi ɗalibi", [
    ['start' => 0, 'end' => 5, 'confidence' => 0.42, 'reason' => 'low_confidence'],
], 'ha');

$sink = new SupabaseEncryptedOcrResultSink(
    'https://example.supabase.co',
    'sb_secret_test',
    'service-role-test',
    $key,
    '1',
    $http,
);
$sink->store($job, $result);

expectSink(is_array($seen), 'Expected a Supabase RPC request.');
expectSink(str_ends_with($seen['url'], '/rest/v1/rpc/store_document_ocr_result'), 'Result must use the service-only RPC.');
expectSink(($seen['headers']['Authorization'] ?? '') === 'Bearer service-role-test', 'RPC must use the service role.');
expectSink(!str_contains(json_encode($seen['body']), 'Ƙarɓi ɗalibi'), 'Plain OCR text must never be sent to Supabase.');
expectSink(($seen['body']['p_encryption_key_version'] ?? null) === '1', 'Expected encryption key version 1.');

$nonce = base64_decode($seen['body']['p_nonce_base64'], true);
$ciphertext = base64_decode($seen['body']['p_ciphertext_base64'], true);
expectSink(is_string($nonce) && strlen($nonce) === SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, 'Nonce length is invalid.');
expectSink(is_string($ciphertext), 'Ciphertext is invalid.');
$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
expectSink(is_string($plain), 'Ciphertext must decrypt with the configured key.');
$decoded = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
expectSink(($decoded['text'] ?? null) === "Ƙarɓi ɗalibi", 'Decrypted OCR text does not match.');
expectSink(($seen['body']['p_result_sha256'] ?? null) === hash_hmac('sha256', $plain, $key), 'Result integrity metadata must use a keyed HMAC.');
expectSink(($decoded['uncertain_spans'][0]['reason'] ?? null) === 'low_confidence', 'Uncertain word markers were not preserved.');

echo "Encrypted OCR result sink tests passed." . PHP_EOL;
