<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class SupabasePaystackReconciliationUnavailableException extends \RuntimeException {}

final class SupabasePaystackReconciliationStore
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
            throw new \InvalidArgumentException('Payment reconciliation is not configured.');
        }
    }

    public function reconcile(
        string $payloadSha256,
        string $reference,
        mixed $verifiedStatus,
        mixed $amountMinor,
        mixed $currency,
        string $providerTransactionId,
        string $paidAt,
    ): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1 ||
            preg_match('/\A[A-Za-z0-9._=-]{8,120}\z/', $reference) !== 1 ||
            !is_string($verifiedStatus) || $verifiedStatus !== 'success' ||
            !is_int($amountMinor) ||
            !is_string($currency) ||
            !in_array($currency, ['NGN', 'USD'], true) ||
            $providerTransactionId === '' || strlen($providerTransactionId) > 120 ||
            strtotime($paidAt) === false) {
            throw new \InvalidArgumentException('Invalid verified payment details.');
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/reconcile_paystack_charge_success';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_payload_sha256' => $payloadSha256,
            'p_reference' => $reference,
            'p_verified_status' => $verifiedStatus,
            'p_amount_minor' => $amountMinor,
            'p_currency' => $currency,
            'p_provider_transaction_id' => $providerTransactionId,
            'p_paid_at' => $paidAt,
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) {
                    throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation is unavailable.');
                }
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation is unavailable.');
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
                    throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation is unavailable.');
                }
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (SupabasePaystackReconciliationUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation is unavailable.');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation was rejected.');
        }

        try {
            $result = json_decode(trim($response['body']), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation returned an invalid response.');
        }
        if (!is_bool($result)) {
            throw new SupabasePaystackReconciliationUnavailableException('Payment reconciliation returned an invalid response.');
        }
        return $result;
    }
}
