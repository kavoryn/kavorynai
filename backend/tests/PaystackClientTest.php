<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Billing/PaystackClient.php';

use Kavoryn\Billing\PaystackClient;
use Kavoryn\Billing\PaystackUnavailableException;

function paystackExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$calls = [];
$mock = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = compact('method', 'url', 'headers', 'body');
    paystackExpect(($headers['Authorization'] ?? null) === 'Bearer sk_test_unit_only', 'Secret key must be sent only in the server-side authorization header.');

    if (str_contains($url, '/subscription/') && str_ends_with($url, '/manage/link')) {
        return [
            'status' => 200,
            'body' => json_encode(['status' => true, 'data' => [
                'link' => 'https://paystack.com/manage/subscriptions/example?subscription_token=opaque',
            ]], JSON_THROW_ON_ERROR),
        ];
    }

    if (str_ends_with($url, '/transaction/initialize')) {
        $input = json_decode((string) $body, true, 16, JSON_THROW_ON_ERROR);
        return [
            'status' => 200,
            'body' => json_encode(['status' => true, 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/example',
                'access_code' => 'access-example',
                'reference' => $input['reference'],
            ]], JSON_THROW_ON_ERROR),
        ];
    }

    return [
        'status' => 200,
        'body' => json_encode(['status' => true, 'data' => [
            'reference' => 'kavoryn-test-0001',
            'amount' => 250000,
            'currency' => 'NGN',
            'status' => 'success',
        ]], JSON_THROW_ON_ERROR),
    ];
};

$client = new PaystackClient('sk_test_unit_only', $mock);
$checkout = $client->initializeTransaction(
    'buyer@example.com',
    250000,
    'NGN',
    'kavoryn-test-0001',
    'https://kavorynai.com.ng/payment/callback',
    ['purpose' => 'subscription_test'],
    'PLN_kavoryn_test_01',
);
paystackExpect($checkout['reference'] === 'kavoryn-test-0001', 'Checkout reference must match the server-created reference.');
paystackExpect($checkout['authorization_url'] === 'https://checkout.paystack.com/example', 'Only the hosted Paystack checkout URL should be returned.');
$initializedPayload = json_decode((string) $calls[0]['body'], true, 16, JSON_THROW_ON_ERROR);
paystackExpect(($initializedPayload['plan'] ?? null) === 'PLN_kavoryn_test_01', 'Recurring checkout must include the server-selected Paystack plan code.');
$verified = $client->verifyTransaction('kavoryn-test-0001');
paystackExpect(($verified['status'] ?? null) === 'success', 'Verification response should expose Paystack transaction status.');
$manageLink = $client->generateSubscriptionManagementLink('SUB_test123456');
paystackExpect(str_starts_with($manageLink, 'https://paystack.com/manage/subscriptions/'), 'Management link must use the official Paystack host.');
paystackExpect($calls[2]['method'] === 'GET' && str_contains($calls[2]['url'], '/subscription/SUB_test123456/manage/link'), 'Management link must use the authenticated Paystack subscription endpoint.');
paystackExpect(count($calls) === 3, 'Initialize, verify, and management-link generation should each make one request.');
paystackExpect($calls[0]['method'] === 'POST' && $calls[1]['method'] === 'GET' && $calls[2]['method'] === 'GET', 'Paystack HTTP methods should be correct.');

try {
    $client->initializeTransaction('not-an-email', 1, 'NGN', 'kavoryn-test-0002', 'https://kavorynai.com.ng/callback');
    throw new RuntimeException('Invalid checkout details must be rejected.');
} catch (InvalidArgumentException) {
}

$badResponse = new PaystackClient('sk_test_unit_only', static fn() => [
    'status' => 200,
    'body' => '{"status":false,"message":"secret provider detail"}',
]);
try {
    $badResponse->verifyTransaction('kavoryn-test-0001');
    throw new RuntimeException('Provider-declared failure must fail closed.');
} catch (PaystackUnavailableException) {
}

$wrongManagementHost = new PaystackClient('sk_test_unit_only', static fn() => [
    'status' => 200,
    'body' => json_encode(['status' => true, 'data' => [
        'link' => 'https://evil.example/manage/subscriptions/example?token=opaque',
    ]], JSON_THROW_ON_ERROR),
]);
try {
    $wrongManagementHost->generateSubscriptionManagementLink('SUB_test123456');
    throw new RuntimeException('Untrusted subscription management hosts must be rejected.');
} catch (PaystackUnavailableException) {
}

$wrongHost = new PaystackClient('sk_test_unit_only', static fn() => [
    'status' => 200,
    'body' => json_encode(['status' => true, 'data' => [
        'authorization_url' => 'https://evil.example/checkout',
        'access_code' => 'access-example',
        'reference' => 'kavoryn-test-0001',
    ],], JSON_THROW_ON_ERROR),
]);
try {
    $wrongHost->initializeTransaction('buyer@example.com', 250000, 'NGN', 'kavoryn-test-0001', 'https://kavorynai.com.ng/callback');
    throw new RuntimeException('Untrusted checkout hosts must be rejected.');
} catch (PaystackUnavailableException) {
}

echo "Paystack client tests passed." . PHP_EOL;
