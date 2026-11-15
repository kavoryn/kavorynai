<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabaseBillingCatalogStore.php';

use Kavoryn\Billing\SupabaseBillingCatalogStore;

function billingCatalogExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$seen = [];
$store = new SupabaseBillingCatalogStore(
    'https://project.supabase.co',
    'sb_publishable_test',
    static function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = compact('url', 'headers', 'body');
        return [
            'status' => 200,
            'body' => json_encode([
                'currency' => 'NGN',
                'plans' => [[
                    'plan_key' => 'plus',
                    'version' => 1,
                    'display_name' => 'Plus',
                    'entitlements' => [],
                    'prices' => [[
                        'price_id' => '11111111-1111-4111-8111-111111111111',
                        'currency' => 'NGN',
                        'amount_minor' => 500000,
                        'interval_unit' => 'month',
                        'interval_count' => 1,
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    },
);
$data = $store->getForBearerToken('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature', 'NGN');
billingCatalogExpect($data['currency'] === 'NGN', 'The selected currency must be preserved.');
billingCatalogExpect($data['plans'][0]['plan_key'] === 'plus', 'Only server-returned plan data should be exposed.');
billingCatalogExpect(str_ends_with($seen['url'], '/rpc/get_active_billing_catalog'), 'The authenticated catalog RPC must be called.');
billingCatalogExpect(($seen['headers']['Authorization'] ?? '') === 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature', 'The user JWT must be forwarded for auth.uid scoping.');
billingCatalogExpect(json_decode($seen['body'], true)['p_currency'] === 'NGN', 'The explicit currency selection must be sent to the RPC.');

try {
    $store->getForBearerToken('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature', 'EUR');
    throw new RuntimeException('Unsupported currencies must be rejected.');
} catch (InvalidArgumentException) {
}

$malformed = new SupabaseBillingCatalogStore(
    'https://project.supabase.co',
    'sb_publishable_test',
    static fn() => ['status' => 200, 'body' => '{"currency":"NGN","plans":[{"plan_key":"plus","display_name":"Plus","entitlements":[],"prices":[{"price_id":"p","currency":"USD","amount_minor":500,"interval_unit":"month","interval_count":1}]}]}'],
);
try {
    $malformed->getForBearerToken('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature', 'NGN');
    throw new RuntimeException('Currency mismatch in provider data must be rejected.');
} catch (RuntimeException $exception) {
    billingCatalogExpect($exception->getMessage() !== 'Currency mismatch in provider data must be rejected.', 'Malformed catalog must fail closed.');
}

echo "Billing catalog store tests passed." . PHP_EOL;
