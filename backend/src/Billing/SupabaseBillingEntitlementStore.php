<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabaseBillingEntitlementStore
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $publishableKey,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' || $publishableKey === '') {
            throw new \InvalidArgumentException('Billing entitlement lookup is not configured.');
        }
    }

    /** @return array<string,mixed> */
    public function getForBearerToken(?string $authorization): array
    {
        if (!is_string($authorization) ||
            preg_match('/\ABearer\s+([A-Za-z0-9._~-]{20,8192})\z/i', trim($authorization), $matches) !== 1) {
            throw new \InvalidArgumentException('A valid user session is required.');
        }
        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/get_my_billing_entitlements';
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
                if (!function_exists('curl_init')) throw new \RuntimeException('Entitlement transport unavailable.');
                $curl = curl_init($url);
                if ($curl === false) throw new \RuntimeException('Entitlement transport unavailable.');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $error !== 0) throw new \RuntimeException('Entitlement transport unavailable.');
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Billing entitlements are temporarily unavailable.');
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300 ||
            strlen($response['body']) > 16384) {
            throw new \RuntimeException('Billing entitlements are temporarily unavailable.');
        }
        $data = json_decode($response['body'], true, 16);
        if (!is_array($data) || !is_string($data['plan_key'] ?? null) ||
            !in_array($data['plan_key'], ['free','plus','pro'], true) ||
            !is_array($data['entitlements'] ?? null)) {
            throw new \RuntimeException('Billing entitlement response is invalid.');
        }
        return $data;
    }
}
