<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabaseBillingSubscriptionStore.php';

use Kavoryn\\Billing\\SupabaseBillingSubscriptionStore;

function billingSubscriptionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$seen = null;
$store = new SupabaseBillingSubscriptionStore(
    'https://project.supabase.co',
    'sb_publishable_unit_test',
    static function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = compact('url', 'headers', 'body');
        return ['status' => 200, 'body' => json_encode('SUB_owned12345', JSON_THROW_ON_ERROR)];
    },
);
$code = $store->getMyPaystackSubscriptionCode('Bearer abcdefghijklmnopqrstuvwxyz123456');
billingSubscriptionExpect($code === 'SUB_owned12345', 'Only the RPC-returned owner-scoped subscription code should be returned.');
billingSubscriptionExpect(str_ends_with($seen['url'], '/rpc/get_my_paystack_subscription_code'), 'The owner-scoped RPC must be called.');
billingSubscriptionExpect(($seen['headers']['Authorization'] ?? '') === 'Bearer abcdefghijklmnopqrstuvwxyz123456', 'The signed-in user token must be forwarded, not a service-role token.');
billingSubscriptionExpect($seen['body'] === '{}', 'The client must not supply a subscription code or user ID.');

$empty = new SupabaseBillingSubscriptionStore(
    'https://project.supabase.co',
    'sb_publishable_unit_test',
    static fn() => ['status' => 200, 'body' => 'null'],
);
billingSubscriptionExpect($empty->getMyPaystackSubscriptionCode('Bearer abcdefghijklmnopqrstuvwxyz123456') === null, 'Users without a manageable subscription should receive null.');

try {
    $store->getMyPaystackSubscriptionCode('not-a-bearer');
    throw new RuntimeException('Invalid user authorization must be rejected.');
} catch (InvalidArgumentException) {
}

$invalid = new SupabaseBillingSubscriptionStore(
    'https://project.supabase.co',
    'sb_publishable_unit_test',
    static fn() => ['status' => 200, 'body' => json_encode('SUB_code\\nattacker', JSON_THROW_ON_ERROR)],
);
try {
    $invalid->getMyPaystackSubscriptionCode('Bearer abcdefghijklmnopqrstuvwxyz123456');
    throw new RuntimeException('Malformed subscription codes must be rejected.');
} catch (RuntimeException $exception) {
    billingSubscriptionExpect($exception->getMessage() !== 'Malformed subscription codes must be rejected.', 'Invalid code should fail closed.');
}

echo "Billing subscription store tests passed." . PHP_EOL;
