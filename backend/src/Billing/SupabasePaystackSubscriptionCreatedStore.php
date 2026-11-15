<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabasePaystackSubscriptionCreatedStore
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
            throw new \InvalidArgumentException('Subscription event processing is not configured.');
        }
    }

    public function apply(
        string $payloadSha256,
        string $subscriptionCode,
        string $customerEmail,
        string $planCode,
        ?string $customerCode,
    ): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1 ||
            preg_match('/\ASUB_[A-Za-z0-9_-]{6,160}\z/', $subscriptionCode) !== 1 ||
            filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/\APLN_[A-Za-z0-9_-]{6,120}\z/', $planCode) !== 1 ||
            ($customerCode !== null && (strlen($customerCode) > 120 || preg_match('/\ACUS_[A-Za-z0-9_-]{6,120}\z/', $customerCode) !== 1))) {
            throw new \InvalidArgumentException('Invalid subscription creation event.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/apply_paystack_subscription_created';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_payload_sha256' => $payloadSha256,
            'p_subscription_code' => $subscriptionCode,
            'p_customer_email' => $customerEmail,
            'p_plan_code' => $planCode,
            'p_customer_code' => $customerCode,
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new \RuntimeException('Subscription event transport unavailable.');
                $curl = curl_init($url);
                if ($curl === false) throw new \RuntimeException('Subscription event transport unavailable.');
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
                if ($responseBody === false || $error !== 0) throw new \RuntimeException('Subscription event transport unavailable.');
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Subscription event processing is temporarily unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) || strlen($response['body']) > 65536 ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Subscription event processing was rejected.');
        }
        $result = json_decode(trim($response['body']), true, 16);
        if (!is_bool($result)) throw new \RuntimeException('Subscription event response is invalid.');
        return $result;
    }
}
