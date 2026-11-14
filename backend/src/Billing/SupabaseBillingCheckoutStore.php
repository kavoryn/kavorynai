<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabaseBillingCheckoutStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '') {
            throw new \InvalidArgumentException('Billing checkout is not configured.');
        }
    }

    /** @return array{reference:string,amount_minor:int,currency:string,email:string,plan_key:string,plan_name:string,plan_code:string} */
    public function createIntent(string $userId, string $priceId, string $reference, string $transactionId, string $subscriptionId): array
    {
        foreach ([$userId, $priceId, $transactionId, $subscriptionId] as $uuid) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $uuid) !== 1) {
                throw new \InvalidArgumentException('Invalid checkout identity.');
            }
        }
        if (preg_match('/\A[A-Za-z0-9._=-]{8,120}\z/', $reference) !== 1) {
            throw new \InvalidArgumentException('Invalid checkout reference.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/create_my_billing_checkout_intent';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_user_id' => $userId,
            'p_price_id' => $priceId,
            'p_reference' => $reference,
            'p_transaction_id' => $transactionId,
            'p_subscription_id' => $subscriptionId,
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new \RuntimeException('Checkout transport unavailable.');
                $curl = curl_init($url);
                if ($curl === false) throw new \RuntimeException('Checkout transport unavailable.');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $error !== 0) throw new \RuntimeException('Checkout transport unavailable.');
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Billing checkout is temporarily unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) || strlen($response['body']) > 65536 ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Billing checkout is temporarily unavailable.');
        }
        $data = json_decode(trim($response['body']), true, 16);
        if (!is_array($data) ||
            ($data['reference'] ?? null) !== $reference ||
            !is_int($data['amount_minor'] ?? null) || $data['amount_minor'] < 1 ||
            !in_array($data['currency'] ?? null, ['NGN','USD'], true) ||
            !is_string($data['email'] ?? null) || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false ||
            !in_array($data['plan_key'] ?? null, ['plus','pro'], true) ||
            !is_string($data['plan_name'] ?? null) ||
            !is_string($data['plan_code'] ?? null) ||
            preg_match('/\APLN_[A-Za-z0-9_-]{6,120}\z/', $data['plan_code']) !== 1) {
            throw new \RuntimeException('Billing checkout details are invalid.');
        }
        return $data;
    }
}
