<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabasePaystackLifecycleStore.php';

use Kavoryn\Billing\SupabasePaystackLifecycleStore;

function lifecycleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$captured = [];
$store = new SupabasePaystackLifecycleStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function (string $url, array $headers, string $body) use (&$captured): array {
        $captured = compact('url', 'headers', 'body');
        return ['status' => 200, 'body' => 'true'];
    },
);
$result = $store->apply(
    str_repeat('a', 64),
    'invoice.payment_failed',
    null,
    'SUB_test_123456',
    'attention',
    72,
);
lifecycleExpect($result, 'Lifecycle RPC success should be returned.');
lifecycleExpect(str_ends_with($captured['url'], '/rpc/apply_paystack_lifecycle_event'), 'Lifecycle store must call the private RPC endpoint.');
$payload = json_decode($captured['body'], true, 16, JSON_THROW_ON_ERROR);
lifecycleExpect(($payload['p_grace_period_hours'] ?? null) === 72, 'Server configured grace period must be sent.');
lifecycleExpect(($payload['p_subscription_code'] ?? null) === 'SUB_test_123456', 'Provider subscription code must be sent.');

$invoiceStore = new SupabasePaystackLifecycleStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function (string $url, array $headers, string $body): array {
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        lifecycleExpect(($payload['p_amount_minor'] ?? null) === 250000, 'Invoice amount must be passed to the server RPC.');
        lifecycleExpect(($payload['p_currency'] ?? null) === 'NGN', 'Invoice currency must be passed to the server RPC.');
        return ['status' => 200, 'body' => 'true'];
    },
);
lifecycleExpect($invoiceStore->apply(
    str_repeat('e', 64),
    'invoice.create',
    'kavoryn-renewal-reference',
    'SUB_test_123456',
    'success',
    null,
    null,
    250000,
    'NGN',
), 'Valid invoice expectation should be accepted by the RPC client.');

$refundStore = new SupabasePaystackLifecycleStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static fn() => ['status' => 200, 'body' => 'false'],
);
lifecycleExpect(!$refundStore->apply(
    str_repeat('b', 64),
    'refund.processed',
    'kavoryn-reference-123',
    null,
    'processed',
    null,
    250000,
), 'Unmatched refund event must not be reported as applied.');

try {
    $store->apply(str_repeat('c', 64), 'invoice.payment_failed', null, 'SUB_test_123456', 'attention', null);
    throw new RuntimeException('Missing grace policy must be rejected.');
} catch (InvalidArgumentException) {
}

try {
    $store->apply(str_repeat('d', 64), 'transfer.success', null, null, 'success', null);
    throw new RuntimeException('Unsupported event must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Paystack lifecycle store tests passed." . PHP_EOL;
