<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class PaystackUnavailableException extends \RuntimeException {}

final class PaystackClient
{
    /**
     * @param callable(string,array<string,string>,?string):array{status:int,body:string}|null $http
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly mixed $http = null,
    ) {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('Paystack is not configured.');
        }
    }

    /**
     * Initialize a hosted Paystack checkout. Amount is in the currency's minor unit.
     *
     * @param array<string,mixed> $metadata
     * @return array{authorization_url:string,access_code:string,reference:string}
     */
    public function initializeTransaction(
        string $email,
        int $amountMinor,
        string $currency,
        string $reference,
        string $callbackUrl,
        array $metadata = [],
    ): array {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            $amountMinor < 1 ||
            !in_array($currency, ['NGN', 'USD'], true) ||
            preg_match('/\A[A-Za-z0-9._=-]{8,120}\z/', $reference) !== 1 ||
            parse_url($callbackUrl, PHP_URL_SCHEME) !== 'https' ||
            !is_array($metadata) ||
            strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Invalid Paystack checkout details.');
        }

        $data = $this->request('POST', '/transaction/initialize', [
            'email' => $email,
            'amount' => $amountMinor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        $url = $data['authorization_url'] ?? null;
        $accessCode = $data['access_code'] ?? null;
        $returnedReference = $data['reference'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            $host !== 'checkout.paystack.com' ||
            !is_string($accessCode) || $accessCode === '' || strlen($accessCode) > 120 ||
            $returnedReference !== $reference) {
            throw new PaystackUnavailableException('Paystack returned an invalid checkout response.');
        }

        return [
            'authorization_url' => $url,
            'access_code' => $accessCode,
            'reference' => $reference,
        ];
    }

    /**
     * Fetch authoritative transaction details. The caller must still compare
     * reference, amount and currency against its server-created payment record.
     *
     * @return array<string,mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        if (preg_match('/\A[A-Za-z0-9._=-]{8,120}\z/', $reference) !== 1) {
            throw new \InvalidArgumentException('Invalid Paystack reference.');
        }

        $data = $this->request('GET', '/transaction/verify/' . rawurlencode($reference), null);
        if (($data['reference'] ?? null) !== $reference ||
            !is_int($data['amount'] ?? null) ||
            !is_string($data['currency'] ?? null) ||
            !is_string($data['status'] ?? null)) {
            throw new PaystackUnavailableException('Paystack returned an invalid verification response.');
        }

        return $data;
    }

    /**
     * Generate Paystack's hosted subscription management link.
     * The caller must obtain the subscription code from an owner-scoped server-side lookup.
     */
    public function generateSubscriptionManagementLink(string $subscriptionCode): string
    {
        if (preg_match('/\\A[A-Za-z0-9_-]{8,160}\\z/', $subscriptionCode) !== 1) {
            throw new \\InvalidArgumentException('Invalid Paystack subscription code.');
        }

        $data = $this->request(
            'GET',
            '/subscription/' . rawurlencode($subscriptionCode) . '/manage/link',
            null,
        );
        $link = $data['link'] ?? null;
        $host = is_string($link) ? parse_url($link, PHP_URL_HOST) : null;
        if (!is_string($link) ||
            parse_url($link, PHP_URL_SCHEME) !== 'https' ||
            !in_array($host, ['paystack.com', 'www.paystack.com'], true) ||
            parse_url($link, PHP_URL_USER) !== null ||
            parse_url($link, PHP_URL_PASS) !== null ||
            parse_url($link, PHP_URL_PORT) !== null) {
            throw new PaystackUnavailableException('Paystack returned an invalid subscription management link.');
        }

        return $link;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $url = 'https://api.paystack.co' . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
        ];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        try {
            if ($this->http !== null) {
                $response = ($this->http)($method, $url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) {
                    throw new PaystackUnavailableException('Paystack transport is unavailable.');
                }
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new PaystackUnavailableException('Paystack transport is unavailable.');
                }
                $headerLines = [];
                foreach ($headers as $name => $value) {
                    $headerLines[] = $name . ': ' . $value;
                }
                $responseBody = '';
                $options = [
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                        if (strlen($responseBody) + strlen($chunk) > 1048576) {
                            return 0;
                        }
                        $responseBody .= $chunk;
                        return strlen($chunk);
                    },
                ];
                if ($body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $body;
                }
                curl_setopt_array($curl, $options);
                $completed = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($completed === false || $error !== 0) {
                    throw new PaystackUnavailableException('Paystack transport failed.');
                }
                $response = ['status' => $status, 'body' => $responseBody];
            }
        } catch (PaystackUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new PaystackUnavailableException('Paystack is temporarily unavailable.');
        }

        if (!is_array($response) ||
            !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            strlen($response['body']) > 1048576 ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new PaystackUnavailableException('Paystack is temporarily unavailable.');
        }

        try {
            $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaystackUnavailableException('Paystack returned an invalid response.');
        }

        if (!is_array($decoded) || ($decoded['status'] ?? null) !== true ||
            !is_array($decoded['data'] ?? null)) {
            throw new PaystackUnavailableException('Paystack did not accept the request.');
        }

        return $decoded['data'];
    }
}
