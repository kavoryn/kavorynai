<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/PaystackWebhookVerifier.php';

use Kavoryn\Billing\InvalidPaystackWebhookException;
use Kavoryn\Billing\PaystackWebhookVerifier;

function webhookExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$secret = 'paystack-test-secret-not-real';
$raw = json_encode([
    'event' => 'charge.success',
    'data' => ['reference' => 'kavoryn-test-0001', 'amount' => 250000, 'currency' => 'NGN'],
], JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha512', $raw, $secret);
$verifier = new PaystackWebhookVerifier($secret);
$event = $verifier->verify($raw, strtoupper($signature));
webhookExpect($event['event'] === 'charge.success', 'Verified event type should be returned.');
webhookExpect(($event['data']['reference'] ?? null) === 'kavoryn-test-0001', 'Verified event data should be returned.');

$rejects = static function (callable $callback, string $message): void {
    try {
        $callback();
        throw new RuntimeException($message);
    } catch (InvalidPaystackWebhookException) {
    }
};

$rejects(static fn() => $verifier->verify($raw . ' ', $signature), 'Modified raw body must be rejected.');
$rejects(static fn() => $verifier->verify($raw, null), 'Missing signature must be rejected.');
$rejects(static fn() => $verifier->verify('{bad json', hash_hmac('sha512', '{bad json', $secret)), 'Signed invalid JSON must be rejected.');
$rejects(static fn() => $verifier->verify(str_repeat('a', 1048577), hash_hmac('sha512', str_repeat('a', 1048577), $secret)), 'Oversized webhook body must be rejected.');

echo "Paystack webhook verifier tests passed." . PHP_EOL;
