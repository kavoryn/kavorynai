<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class PaystackWebhookStorageUnavailableException extends \RuntimeException {}

final class SupabasePaystackWebhookStore
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
            throw new \InvalidArgumentException('Webhook storage is not configured.');
        }
    }

    public function recordVerifiedReceipt(
        string $id,
        string $payloadSha256,
        string $eventType,
        ?string $eventReference,
    ): bool {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) !== 1 ||
            preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1 ||
            $eventType === '' || strlen($eventType) > 120 ||
            ($eventReference !== null && strlen($eventReference) > 120)) {
            throw new \InvalidArgumentException('Invalid verified webhook receipt.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/record_paystack_webhook_receipt';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_id' => $id,
            'p_payload_sha256' => $payloadSha256,
            'p_event_type' => $eventType,
            'p_event_reference' => $eventReference,
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) {
                    throw new PaystackWebhookStorageUnavailableException('Webhook storage is unavailable.');
                }
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new PaystackWebhookStorageUnavailableException('Webhook storage is unavailable.');
                }
                $lines = [];
                foreach ($headers as $name => $value) {
                    $lines[] = $name . ': ' . $value;
                }
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
                if ($responseBody === false || $error !== 0) {
                    throw new PaystackWebhookStorageUnavailableException('Webhook storage is unavailable.');
                }
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (PaystackWebhookStorageUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new PaystackWebhookStorageUnavailableException('Webhook storage is unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new PaystackWebhookStorageUnavailableException('Webhook storage rejected the receipt.');
        }

        try {
            $result = json_decode(trim($response['body']), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaystackWebhookStorageUnavailableException('Webhook storage returned an invalid response.');
        }

        if (!is_bool($result)) {
            throw new PaystackWebhookStorageUnavailableException('Webhook storage returned an invalid response.');
        }
        return $result;
    }
}
