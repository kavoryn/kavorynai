<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

use DateTimeImmutable;

final class SupabaseEncryptedOcrResultReader
{
    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly string $encryptionKey,
        private readonly mixed $http = null,
    ) {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \InvalidArgumentException('The PHP sodium extension is required.');
        }
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '' ||
            strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Encrypted OCR result reader is not configured.');
        }
    }

    public static function decodeKey(string $base64Key): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \InvalidArgumentException('The PHP sodium extension is required.');
        }
        $decoded = base64_decode($base64Key, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('OCR_RESULT_ENCRYPTION_KEY must be base64 for exactly 32 random bytes.');
        }
        return $decoded;
    }

    public function read(string $documentId, string $userId): OcrResult
    {
        foreach ([$documentId, $userId] as $id) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
                throw new \InvalidArgumentException('Invalid OCR result owner or document identifier.');
            }
        }

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/get_document_ocr_result';
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $body = json_encode([
                'p_document_id' => $documentId,
                'p_user_id' => $userId,
            ], JSON_THROW_ON_ERROR);
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $body);
            } else {
                if (!function_exists('curl_init')) throw new OcrResultReaderUnavailableException();
                $curl = curl_init($url);
                if ($curl === false) throw new OcrResultReaderUnavailableException();
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $responseBody = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($curl);
                curl_close($curl);
                if ($responseBody === false || $errno !== 0) throw new OcrResultReaderUnavailableException();
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (OcrResultReaderUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new OcrResultReaderUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new OcrResultReaderUnavailableException();
        }

        try {
            $rows = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrResultReaderUnavailableException();
        }
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if ($row === null) throw new OcrResultNotFoundException();

        if (!is_array($row) ||
            !is_string($row['ciphertext_base64'] ?? null) ||
            !is_string($row['nonce_base64'] ?? null) ||
            ($row['encryption_key_version'] ?? null) !== '1' ||
            !is_string($row['result_sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/', $row['result_sha256']) ||
            !is_string($row['detected_language'] ?? null) ||
            !is_int($row['character_count'] ?? null) ||
            !is_int($row['uncertain_span_count'] ?? null) ||
            !is_string($row['expires_at'] ?? null)) {
            throw new OcrResultReaderUnavailableException();
        }

        try {
            $expiresAt = new DateTimeImmutable($row['expires_at']);
        } catch (\Throwable) {
            throw new OcrResultReaderUnavailableException();
        }
        if ($expiresAt <= new DateTimeImmutable('now')) throw new OcrResultNotFoundException();

        $nonce = base64_decode($row['nonce_base64'], true);
        $ciphertext = base64_decode($row['ciphertext_base64'], true);
        if (!is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ||
            !is_string($ciphertext)) {
            throw new OcrResultReaderUnavailableException();
        }

        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);
        if (!is_string($plain) || !hash_equals(hash_hmac('sha256', $plain, $this->encryptionKey), $row['result_sha256'])) {
            throw new OcrResultReaderUnavailableException();
        }

        try {
            $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrResultReaderUnavailableException();
        }

        if (!is_array($decoded) ||
            !is_string($decoded['text'] ?? null) ||
            !is_array($decoded['uncertain_spans'] ?? null) ||
            !is_string($decoded['detected_language'] ?? null)) {
            throw new OcrResultReaderUnavailableException();
        }

        try {
            $result = new OcrResult(
                $decoded['text'],
                $decoded['uncertain_spans'],
                $decoded['detected_language'],
            );
        } catch (\InvalidArgumentException) {
            throw new OcrResultReaderUnavailableException();
        }

        preg_match_all('/./us', $result->text, $characters);
        if (count($characters[0]) !== $row['character_count'] ||
            count($result->uncertainSpans) !== $row['uncertain_span_count']) {
            throw new OcrResultReaderUnavailableException();
        }

        return $result;
    }
}

final class OcrResultNotFoundException extends \RuntimeException {}
final class OcrResultReaderUnavailableException extends \RuntimeException {}
