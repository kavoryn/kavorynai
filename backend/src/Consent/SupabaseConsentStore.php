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
        if ($supabaseUrl === '' || parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '') {
            throw new ConsentStorageUnavailableException('Consent storage is not configured.');
        }
    }

    public function create(ConsentRecord $record): ConsentRecord
    {
        $ttlSeconds = $record->expiresAt->getTimestamp() - $record->createdAt->getTimestamp();
        $body = $this->post('/rest/v1/rpc/create_processing_consent', [
            'p_id' => $record->id,
            'p_user_id' => $record->userId,
            'p_action' => $record->action,
            'p_payload_sha256' => $record->payloadSha256,
            'p_output_language' => $record->language,
            'p_translate' => $record->translate,
            'p_token_hash' => $record->tokenHash,
            'p_ttl_seconds' => $ttlSeconds,
        ]);

        try {
            $rows = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConsentStorageUnavailableException('Invalid consent creation response.');
        }

        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row) ||
            !is_string($row['consent_id'] ?? null) ||
            !is_string($row['consented_at'] ?? null) ||
            !is_string($row['expires_at'] ?? null)) {
            throw new ConsentStorageUnavailableException('Invalid consent creation response.');
        }

        try {
            $createdAt = new DateTimeImmutable($row['consented_at']);
            $expiresAt = new DateTimeImmutable($row['expires_at']);
        } catch (\Throwable) {
            throw new ConsentStorageUnavailableException('Invalid consent timestamps returned by database.');
        }

        return new ConsentRecord(
            $row['consent_id'],
            $record->userId,
            $record->action,
            $record->payloadSha256,
            $record->language,
            $record->translate,
            $record->tokenHash,
            $createdAt,
            $expiresAt,
        );
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
