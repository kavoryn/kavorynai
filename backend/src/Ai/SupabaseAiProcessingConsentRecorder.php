<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class SupabaseAiProcessingConsentRecorder
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' || $secretKey === '' || $serviceRoleJwt === '') {
            throw new \InvalidArgumentException('AI consent storage is not configured.');
        }
    }

    public function record(string $userId, string $documentId, string $provider, string $language, string $purpose = 'explain'): void
    {
        foreach ([$userId, $documentId] as $id) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
                throw new \InvalidArgumentException('Invalid AI consent identity.');
            }
        }
        if (!in_array($provider, ['openai', 'gemini'], true) || !in_array($language, ['en', 'ha', 'ar', 'fr'], true) || !in_array($purpose, ['explain', 'ask'], true)) {
            throw new \InvalidArgumentException('Invalid AI consent selection.');
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $id = sprintf('%s-%s-%s-%s-%s', substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20,12));

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/record_ai_processing_consent';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $body = json_encode([
            'p_id' => $id,
            'p_user_id' => $userId,
            'p_document_id' => $documentId,
            'p_provider_name' => $provider,
            'p_output_language' => $language,
            'p_purpose' => $purpose === 'ask'
                ? 'Answer a question using the selected external AI provider'
                : 'Explain OCR text using the selected external AI provider',
        ], JSON_THROW_ON_ERROR);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new AiConsentStorageUnavailableException();
                $curl = curl_init($url);
                if ($curl === false) throw new AiConsentStorageUnavailableException();
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
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
                $errno = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $errno !== 0) throw new AiConsentStorageUnavailableException();
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (AiConsentStorageUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new AiConsentStorageUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) || $response['status'] < 200 || $response['status'] >= 300) {
            throw new AiConsentStorageUnavailableException();
        }
        try {
            $rows = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiConsentStorageUnavailableException();
        }
        if (!is_array($rows) || !is_array($rows[0] ?? null) ||
            ($rows[0]['consent_id'] ?? null) !== $id ||
            !is_string($rows[0]['document_sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/', $rows[0]['document_sha256']) ||
            !is_string($rows[0]['consented_at'] ?? null)) {
            throw new AiConsentStorageUnavailableException();
        }
    }
}

final class AiConsentStorageUnavailableException extends \RuntimeException {}
