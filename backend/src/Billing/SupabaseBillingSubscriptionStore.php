<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabaseBillingSubscriptionStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $publishableKey,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' || $publishableKey === '') {
            throw new \InvalidArgumentException('Billing subscription lookup is not configured.');
        }
    }

    public function getMyPaystackSubscriptionCode(?string $authorization): ?string
    {
        if (!is_string($authorization) ||
            preg_match('/\\ABearer\\s+([A-Za-z0-9._~-]{20,8192})\\z/i', trim($authorization), $matches) !== 1) {
            throw new \InvalidArgumentException('A valid user session is required.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/get_my_paystack_subscription_code';
        $headers = [
            'apikey' => $this->publishableKey,
            'Authorization' => 'Bearer ' . $matches[1],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, '{}');
            } else {
                if (!function_exists('curl_init')) {
                    throw new \RuntimeException('Subscription lookup transport unavailable.');
                }
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new \RuntimeException('Subscription lookup transport unavailable.');
                }
                $lines = [];
                foreach ($headers as $name => $value) {
                    $lines[] = $name . ': ' . $value;
                }
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '{}',
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $error !== 0) {
                    throw new \RuntimeException('Subscription lookup transport unavailable.');
                }
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Billing subscription lookup is temporarily unavailable.');
        }

        if (!is_array($response) ||
            !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300 ||
            strlen($response['body']) > 4096) {
            throw new \RuntimeException('Billing subscription lookup is temporarily unavailable.');
        }

        $code = json_decode($response['body'], true, 8);
        if ($code === null) {
            return null;
        }
        if (!is_string($code) || preg_match('/\\A[A-Za-z0-9_-]{8,160}\\z/', $code) !== 1) {
            throw new \RuntimeException('Billing subscription lookup response is invalid.');
        }
        return $code;
    }
}
