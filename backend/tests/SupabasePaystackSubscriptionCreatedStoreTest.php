<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/SupabasePaystackSubscriptionCreatedStore.php';

use Kavoryn\Billing\SupabasePaystackSubscriptionCreatedStore;

function subscriptionCreatedExpect(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,$message.PHP_EOL);exit(1);}
}

$capture=[];
$store=new SupabasePaystackSubscriptionCreatedStore(
    'https://project.supabase.co',
    'sb_secret_test',
    'service-role-test',
    static function(string $url,array $headers,string $body)use(&$capture):array{
        $capture=compact('url','headers','body');
        return ['status'=>200,'body'=>'true'];
    },
);
$result=$store->apply(
    str_repeat('a',64),
    'SUB_kavoryn_test_123',
    'buyer@example.com',
    'PLN_kavoryn_test_01',
    'CUS_kavoryn_test_123',
);
subscriptionCreatedExpect($result,'Verified subscription creation event should be applied.');
subscriptionCreatedExpect(str_ends_with($capture['url'],'/rpc/apply_paystack_subscription_created'),'Subscription event must call the restricted reconciliation RPC.');
$payload=json_decode($capture['body'],true,16,JSON_THROW_ON_ERROR);
subscriptionCreatedExpect(($payload['p_plan_code']??null)==='PLN_kavoryn_test_01','Merchant plan code must be included for server-side matching.');
subscriptionCreatedExpect(($capture['headers']['Authorization']??null)==='Bearer service-role-test','Service role remains server-side.');

try{
    $store->apply(str_repeat('a',64),'SUB_bad','buyer@example.com','PLN_kavoryn_test_01',null);
    throw new RuntimeException('Invalid subscription codes must be rejected.');
}catch(InvalidArgumentException){
}

echo "Paystack subscription-created store tests passed.".PHP_EOL;
