<?php

declare(strict_types=1);

namespace Kavoryn\\Billing;

final class SupabaseBillingCatalogStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $publishableKey,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' || $publishableKey === '') {
            throw new \\InvalidArgumentException('Billing catalog lookup is not configured.');
        }
    }

    /** @return array{currency:string,plans:list<array<string,mixed>>} */
    public function getForBearerToken(?string $authorization, string $currency): array
    {
        if (!in_array($currency, ['NGN', 'USD'], true)) {
            throw new \\InvalidArgumentException('Choose a supported billing currency.');
        }
        if (!is_string($authorization) ||
            preg_match('/\\ABearer\\s+([A-Za-z0-9._~-]{20,8192})\\z/i', trim($authorization), $matches) !== 1) {
            throw new \\InvalidArgumentException('A valid user session is required.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/get_active_billing_catalog';
        $headers = [
            'apikey' => $this->publishableKey,
            'Authorization' => 'Bearer ' . $matches[1],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, json_encode(['p_currency' => $currency], JSON_THROW_ON_ERROR));
            } else {
                if (!function_exists('curl_init')) throw new \\RuntimeException('Catalog transport unavailable.');
                $curl = curl_init($url);
                if ($curl === false) throw new \\RuntimeException('Catalog transport unavailable.');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['p_currency' => $currency], JSON_THROW_ON_ERROR),
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
                if ($body === false || $error !== 0) throw new \\RuntimeException('Catalog transport unavailable.');
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (\\Throwable) {
            throw new \\RuntimeException('Billing catalog is temporarily unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) || $response['status'] < 200 ||
            $response['status'] >= 300 || strlen($response['body']) > 65536) {
            throw new \\RuntimeException('Billing catalog is temporarily unavailable.');
        }
        $data = json_decode($response['body'], true, 32);
        if (!is_array($data) || ($data['currency'] ?? null) !== $currency ||
            !is_array($data['plans'] ?? null) || !array_is_list($data['plans'])) {
            throw new \\RuntimeException('Billing catalog response is invalid.');
        }

        foreach ($data['plans'] as $plan) {
            if (!is_array($plan) ||
                !in_array($plan['plan_key'] ?? null, ['free', 'plus', 'pro'], true) ||
                !is_string($plan['display_name'] ?? null) ||
                !is_array($plan['entitlements'] ?? null) ||
                !is_array($plan['prices'] ?? null) || !array_is_list($plan['prices'])) {
                throw new \\RuntimeException('Billing catalog response is invalid.');
            }
            foreach ($plan['prices'] as $price) {
                if (!is_array($price) || !is_string($price['price_id'] ?? null) ||
                    ($price['currency'] ?? null) !== $currency ||
                    !is_int($price['amount_minor'] ?? null) || $price['amount_minor'] < 1 ||
                    !in_array($price['interval_unit'] ?? null, ['month', 'year'], true) ||
                    !is_int($price['interval_count'] ?? null) || $price['interval_count'] < 1 || $price['interval_count'] > 12) {
                    throw new \\RuntimeException('Billing catalog response is invalid.');
                }
            }
        }
        return ['currency' => $currency, 'plans' => $data['plans']];
    }
}
