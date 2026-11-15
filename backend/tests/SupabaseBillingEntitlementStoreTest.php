<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabaseBillingEntitlementStore.php';

use Kavoryn\Billing\SupabaseBillingEntitlementStore;

function entitlementExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$seen = [];
$store = new SupabaseBillingEntitlementStore(
    'https://project.supabase.co',
    'sb_publishable_test',
    static function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = compact('url', 'headers', 'body');
        return [
            'status' => 200,
            'body' => '{"plan_key":"plus","plan_version":1,"entitlements":{"chat":true},"subscription_status":"active"}',
        ];
    },
);
$data = $store->getForBearerToken('Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature');
entitlementExpect(($data['plan_key'] ?? null) === 'plus', 'User-scoped Plus entitlement should be returned.');
entitlementExpect(str_ends_with($seen['url'], '/rpc/get_my_billing_entitlements'), 'The authenticated entitlement RPC must be called.');
entitlementExpect(($seen['headers']['Authorization'] ?? '') === 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyIn0.signature', 'User JWT must be forwarded for auth.uid scoping.');
entitlementExpect(!isset($seen['headers']['service_role']), 'No service role header may be used for user entitlement reads.');

try {
    $store->getForBearerToken(null);
    throw new RuntimeException('Missing bearer token must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Billing entitlement store tests passed." . PHP_EOL;
