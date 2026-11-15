<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabasePaystackWebhookStore.php';

use Kavoryn\Billing\SupabasePaystackWebhookStore;
use Kavoryn\Billing\PaystackWebhookStorageUnavailableException;

function receiptExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$calls = [];
$store = new SupabasePaystackWebhookStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function (string $url, array $headers, string $body) use (&$calls): array {
        $calls[] = compact('url', 'headers', 'body');
        receiptExpect(str_ends_with($url, '/rpc/record_paystack_webhook_receipt'), 'Receipt should use the server-side RPC.');
        receiptExpect(($headers['Authorization'] ?? null) === 'Bearer service-role-test', 'Service credentials must remain server-side.');
        return ['status' => 200, 'body' => 'true'];
    },
);

$created = $store->recordVerifiedReceipt(
    '123e4567-e89b-42d3-a456-426614174000',
    str_repeat('a', 64),
    'charge.success',
    'kavoryn-test-0001',
);
receiptExpect($created, 'First delivery should create a receipt.');
receiptExpect(count($calls) === 1, 'One RPC should be issued.');

$duplicateStore = new SupabasePaystackWebhookStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static fn() => ['status' => 200, 'body' => 'false'],
);
receiptExpect(!$duplicateStore->recordVerifiedReceipt(
    '123e4567-e89b-42d3-a456-426614174001',
    str_repeat('a', 64),
    'charge.success',
    'kavoryn-test-0001',
), 'Duplicate delivery should be identified.');

$failedStore = new SupabasePaystackWebhookStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static fn() => ['status' => 503, 'body' => 'unavailable'],
);
try {
    $failedStore->recordVerifiedReceipt(
        '123e4567-e89b-42d3-a456-426614174002',
        str_repeat('b', 64),
        'charge.success',
        null,
    );
    throw new RuntimeException('Storage failure must not acknowledge a webhook.');
} catch (PaystackWebhookStorageUnavailableException) {
}

echo "Paystack webhook receipt store tests passed." . PHP_EOL;
