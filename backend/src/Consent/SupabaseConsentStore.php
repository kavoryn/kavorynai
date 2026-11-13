<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use DateTimeImmutable;

final class SupabaseConsentStore implements ConsentStore
{
    /** @param callable(string, array<string, string>, ?string): array{status:int, body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '') {
            throw new ConsentStorageUnavailableException('Consent storage is not configured.');
        }
    }

    public function create(ConsentRecord $record): void
    {
        $payload = [
            'user_id' => $record->userId,
            'feature' => 'document',
            'provider_name' => null,
            'purpose' => 'User-authorized document OCR and explanation',
            'consented_at' => $record->createdAt->format(DATE_ATOM),
            'confirmation_token_hash' => $record->tokenHash,
            'expires_at' => $record->expiresAt->format(DATE_ATOM),
            'revoked_at' => null,
            'action' => $record->action,
            'payload_sha256' => $record->payloadSha256,
            'output_language' => $record->language,
            'translate' => $record->translate,
            'consumed_at' => null,
        ];
        $this->post('/rest/v1/processing_consents', $payload, ['Prefer: return=minimal']);
    }

    public function consumeOnce(string $tokenHash, string $userId, string $action, string $payloadSha256, string $language, bool $translate, DateTimeImmutable $now): bool
    {
        $body = $this->post('/rest/v1/rpc/consume_processing_consent', [
            'p_token_hash' => $tokenHash,
            'p_user_id' => $userId,
            'p_action' => $action,
            'p_payload_sha256' => $payloadSha256,
            'p_output_language' => $language,
            'p_translate' => $translate,
        ]);
        if ($body === 'true') return true;
        if ($body === 'false') return false;
        throw new ConsentStorageUnavailableException('Invalid consent-consumption response.');
    }

    /** @param array<string, mixed> $payload @param list<string> $extraHeaders */
    private function post(string $path, array $payload, array $extraHeaders = []): string
    {
        $url = rtrim($this->supabaseUrl, '/') . $path;
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $json);
            } else {
                if (!function_exists('curl_init')) throw new ConsentStorageUnavailableException('PHP cURL is required.');
                $curl = curl_init($url);
                if ($curl === false) throw new ConsentStorageUnavailableException('Could not initialize database client.');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => array_merge($lines, $extraHeaders),
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $error !== 0) throw new ConsentStorageUnavailableException('Database service unavailable.');
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (\Throwable) {
            throw new ConsentStorageUnavailableException('Consent storage is temporarily unavailable.');
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null) || $response['status'] < 200 || $response['status'] >= 300) {
            throw new ConsentStorageUnavailableException('Consent storage rejected the operation.');
        }
        return trim($response['body']);
    }
}
