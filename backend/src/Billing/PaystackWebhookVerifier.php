<?php

declare(strict_types=1);

namespace Kavoryn\Billing;

final class InvalidPaystackWebhookException extends \RuntimeException {}

final class PaystackWebhookVerifier
{
    public function __construct(private readonly string $secretKey)
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('Paystack webhook verification is not configured.');
        }
    }

    /**
     * Verify the HMAC-SHA512 signature against the exact raw request body.
     *
     * @return array{event:string,data:array<string,mixed>}
     */
    public function verify(string $rawBody, ?string $signature): array
    {
        if ($rawBody === '' || strlen($rawBody) > 1048576 ||
            !is_string($signature) ||
            preg_match('/\A[0-9a-f]{128}\z/i', $signature) !== 1) {
            throw new InvalidPaystackWebhookException('Invalid Paystack webhook.');
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new InvalidPaystackWebhookException('Invalid Paystack webhook.');
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPaystackWebhookException('Invalid Paystack webhook.');
        }

        if (!is_array($payload) || array_is_list($payload) ||
            !is_string($payload['event'] ?? null) ||
            $payload['event'] === '' || strlen($payload['event']) > 120 ||
            !is_array($payload['data'] ?? null)) {
            throw new InvalidPaystackWebhookException('Invalid Paystack webhook.');
        }

        return ['event' => $payload['event'], 'data' => $payload['data']];
    }
}
