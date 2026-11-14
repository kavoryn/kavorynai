<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabasePaystackLifecycleStore
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
            throw new \InvalidArgumentException('Billing lifecycle is not configured.');
        }
    }

    public function apply(
        string $payloadSha256,
        string $eventType,
        ?string $reference,
        ?string $subscriptionCode,
        ?string $providerStatus,
        ?int $gracePeriodHours,
        ?int $refundAmountMinor = null,
    ): bool {
        $allowed = [
            'invoice.payment_failed', 'subscription.not_renew', 'subscription.disable',
            'refund.pending', 'refund.processing', 'refund.processed', 'refund.failed',
        ];
        if (preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1 ||
            !in_array($eventType, $allowed, true) ||
            ($reference !== null && strlen($reference) > 120) ||
            ($subscriptionCode !== null && strlen($subscriptionCode) > 160) ||
            ($providerStatus !== null && strlen($providerStatus) > 80) ||
            ($eventType !== 'invoice.payment_failed' && $gracePeriodHours !== null) ||
            (str_starts_with($eventType, 'refund.') && ($refundAmountMinor === null || $refundAmountMinor < 0)) ||
            (!str_starts_with($eventType, 'refund.') && $refundAmountMinor !== null) ||
            ($eventType === 'invoice.payment_failed' &&
                ($gracePeriodHours === null || $gracePeriodHours < 1 || $gracePeriodHours > 720))) {
            throw new \InvalidArgumentException('Invalid billing lifecycle event.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/apply_paystack_lifecycle_event';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_payload_sha256' => $payloadSha256,
            'p_event_type' => $eventType,
            'p_reference' => $reference,
            'p_subscription_code' => $subscriptionCode,
            'p_provider_status' => $providerStatus,
            'p_grace_period_hours' => $gracePeriodHours,
            'p_refund_amount_minor' => $refundAmountMinor,
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) {
                    throw new \RuntimeException('Billing lifecycle transport is unavailable.');
                }
                $curl = curl_init($url);
                if ($curl === false) throw new \RuntimeException('Billing lifecycle transport is unavailable.');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $error !== 0) throw new \RuntimeException('Billing lifecycle transport is unavailable.');
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Billing lifecycle processing is unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Billing lifecycle processing was rejected.');
        }
        $result = json_decode(trim($response['body']), true, 16);
        if (!is_bool($result)) throw new \RuntimeException('Billing lifecycle response is invalid.');
        return $result;
    }
}
