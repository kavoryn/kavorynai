<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

use DateTimeImmutable;

final class SupabaseDocumentRegistry implements DocumentRegistry
{
    /** @param callable(string, array<string, string>, string): array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '') {
            throw new DocumentRegistryUnavailableException('Document registry is not configured.');
        }
    }

    public function register(
        string $documentId,
        string $userId,
        string $consentTokenHash,
        InspectedDocument $document,
        string $language,
        bool $translate,
        string $storageKey,
        string $idempotencyKey,
    ): DocumentRegistration {
        $payload = [
            'p_id' => $documentId,
            'p_user_id' => $userId,
            'p_consent_token_hash' => $consentTokenHash,
            'p_action' => 'document.process',
            'p_payload_sha256' => $document->sha256,
            'p_output_language' => $language,
            'p_translate' => $translate,
            'p_storage_key' => $storageKey,
            'p_mime_type' => $document->mimeType,
            'p_file_extension' => $document->extension,
            'p_size_bytes' => $document->sizeBytes,
            'p_idempotency_key' => $idempotencyKey,
        ];

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/register_uploaded_document';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $json);
            } else {
                if (!function_exists('curl_init')) {
                    throw new DocumentRegistryUnavailableException('PHP cURL is required.');
                }
                $curl = curl_init($url);
                if ($curl === false) {
                    throw new DocumentRegistryUnavailableException();
                }
                $headerLines = [];
                foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $headerLines,
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
                    throw new DocumentRegistryUnavailableException();
                }
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (\Throwable) {
            throw new DocumentRegistryUnavailableException();
        }

        if (!is_array($response) ||
            !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 ||
            $response['status'] >= 300) {
            $errorBody = is_string($response['body'] ?? null) ? $response['body'] : '';
            if (str_contains($errorBody, 'Consent is invalid, expired, mismatched, or already used')) {
                throw new DocumentConsentRejectedException();
            }
            if (str_contains($errorBody, 'Idempotency key conflict')) {
                throw new DocumentIdempotencyConflictException();
            }
            throw new DocumentRegistryUnavailableException();
        }

        try {
            $rows = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DocumentRegistryUnavailableException('Invalid document registry response.');
        }

        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row) ||
            !is_string($row['document_id'] ?? null) ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $row['document_id']) ||
            !is_string($row['document_status'] ?? null) ||
            !in_array($row['document_status'], ['uploaded', 'queued', 'processing', 'succeeded', 'failed', 'deleted'], true) ||
            !is_string($row['registered_at'] ?? null) ||
            !is_string($row['stored_key'] ?? null) ||
            !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $row['stored_key'])) {
            throw new DocumentRegistryUnavailableException('Invalid document registry response.');
        }

        try {
            $createdAt = new DateTimeImmutable($row['registered_at']);
        } catch (\Throwable) {
            throw new DocumentRegistryUnavailableException('Invalid document registry timestamp.');
        }

        return new DocumentRegistration(
            $row['document_id'],
            $row['document_status'],
            $createdAt,
            $row['stored_key'],
        );
    }
}
