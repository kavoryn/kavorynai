<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabaseBillingCheckoutStore.php';

use Kavoryn\Billing\SupabaseBillingCheckoutStore;

function checkoutExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$captured = [];
$store = new SupabaseBillingCheckoutStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function (string $url, array $headers, string $body) use (&$captured): array {
        $captured = compact('url','headers','body');
        return ['status'=>200,'body'=>json_encode([
            'reference'=>'kvn_1234567890123456789012345678901234567890',
            'amount_minor'=>500000,
            'currency'=>'NGN',
            'email'=>'buyer@example.com',
            'plan_key'=>'plus',
            'plan_name'=>'Plus',
            'plan_code'=>'PLN_kavoryn_test_01',
        ],JSON_THROW_ON_ERROR)];
    },
);
$result=$store->createIntent(
    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    'kvn_1234567890123456789012345678901234567890',
    'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
);
checkoutExpect($result['plan_code']==='PLN_kavoryn_test_01','Checkout plan code must come from the server-side price record.');
checkoutExpect(str_ends_with($captured['url'],'/rpc/create_my_billing_checkout_intent'),'The trusted checkout intent RPC must be called.');
checkoutExpect(($captured['headers']['Authorization']??'')==='Bearer service-role-test','Only the server-side service role may create the intent.');
$payload=json_decode($captured['body'],true,16,JSON_THROW_ON_ERROR);
checkoutExpect(($payload['p_user_id']??null)==='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','The authenticated user identity must be passed by the API.');
checkoutExpect(($payload['p_price_id']??null)==='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','Only the selected price ID is accepted from the client.');

$invalid=new SupabaseBillingCheckoutStore(
    'https://project.supabase.co','sb_secret_test','service-role-test',
    static fn()=>['status'=>200,'body'=>'{"reference":"wrong","amount_minor":100,"currency":"NGN","email":"x@example.com","plan_key":"plus","plan_name":"Plus","plan_code":"PLN_kavoryn_test_01"}'],
);
try {
    $invalid->createIntent(
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'kvn_1234567890123456789012345678901234567890',
        'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    );
    throw new RuntimeException('Mismatched checkout reference must fail closed.');
} catch (RuntimeException $exception) {
    checkoutExpect($exception->getMessage()!=='Mismatched checkout reference must fail closed.','Invalid checkout response must be rejected.');
}

echo "Billing checkout store tests passed.".PHP_EOL;
