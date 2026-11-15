<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabasePaystackReconciliationStore.php';

use Kavoryn\Billing\SupabasePaystackReconciliationStore;

function reconciliationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$requests = [];
$store = new SupabasePaystackReconciliationStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function (string $url, array $headers, string $body) use (&$requests): array {
        $requests[] = compact('url', 'headers', 'body');
        reconciliationExpect(str_ends_with($url, '/rpc/reconcile_paystack_charge_success'), 'Reconciliation must call the atomic RPC.');
        reconciliationExpect(($headers['Authorization'] ?? null) === 'Bearer service-role-test', 'Service role credentials must remain server-side.');
        return ['status' => 200, 'body' => 'true'];
    },
);

$ok = $store->reconcile(
    str_repeat('a', 64),
    'kavoryn-test-0001',
    'success',
    250000,
    'NGN',
    '987654321',
    '2026-11-14T00:00:00+00:00',
);
reconciliationExpect($ok, 'A reconciled successful payment should return true.');
$payload = json_decode($requests[0]['body'], true, 16, JSON_THROW_ON_ERROR);
reconciliationExpect(($payload['p_amount_minor'] ?? null) === 250000, 'Expected verified amount should be sent to the RPC.');
reconciliationExpect(($payload['p_currency'] ?? null) === 'NGN', 'Expected verified currency should be sent to the RPC.');

$mismatchStore = new SupabasePaystackReconciliationStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static fn() => ['status' => 200, 'body' => 'false'],
);
reconciliationExpect(!$mismatchStore->reconcile(
    str_repeat('b', 64),
    'kavoryn-test-0002',
    'success',
    1,
    'USD',
    '987654322',
    '2026-11-14T00:00:00+00:00',
), 'A payment mismatch rejected by the database must not reconcile.');

$duplicateStore = new SupabasePaystackReconciliationStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static fn() => ['status' => 200, 'body' => 'true'],
);
reconciliationExpect($duplicateStore->reconcile(
    str_repeat('a', 64),
    'kavoryn-test-0001',
    'success',
    250000,
    'NGN',
    '987654321',
    '2026-11-14T00:00:00+00:00',
), 'A database-confirmed duplicate should be idempotently acknowledged.');

try {
    $store->reconcile(
        str_repeat('c', 64),
        'kavoryn-test-0003',
        'failed',
        250000,
        'NGN',
        '987654323',
        '2026-11-14T00:00:00+00:00',
    );
    throw new RuntimeException('Failed Paystack verification must not be treated as successful.');
} catch (InvalidArgumentException) {
}

try {
    $store->reconcile(
        str_repeat('d', 64),
        'kavoryn-test-0004',
        'success',
        '250000',
        'NGN',
        '987654324',
        '2026-11-14T00:00:00+00:00',
    );
    throw new RuntimeException('String amounts must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Paystack reconciliation store tests passed." . PHP_EOL;
