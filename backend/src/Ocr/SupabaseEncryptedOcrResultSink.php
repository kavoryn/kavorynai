<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final class SupabaseEncryptedOcrResultSink implements OcrResultSink
{
    /**
     * @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http
     */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly string $encryptionKey,
        private readonly string $keyVersion = '1',
        private readonly mixed $http = null,
    ) {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \InvalidArgumentException('The PHP sodium extension is required for encrypted OCR results.');
        }
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '' ||
            strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ||
            $keyVersion !== '1') {
            throw new \InvalidArgumentException('Encrypted OCR result storage is not configured.');
        }
    }

    public static function decodeKey(string $base64Key): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \InvalidArgumentException('The PHP sodium extension is required for encrypted OCR results.');
        }
        $decoded = base64_decode($base64Key, true);
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('OCR_RESULT_ENCRYPTION_KEY must be base64 for exactly 32 random bytes.');
        }
        return $decoded;
    }

    public function store(OcrJob $job, OcrResult $result): void
    {
        $plain = json_encode([
            'text' => $result->text,
            'uncertain_spans' => $result->uncertainSpans,
            'detected_language' => $result->detectedLanguage ?? 'und',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $plainBytes = strlen($plain);
        if ($plainBytes < 2 || $plainBytes > 5 * 1024 * 1024) {
            throw new OcrResultStorageException('ocr_result_size_invalid');
        }

        preg_match_all('/./us', $result->text, $characters);
        $characterCount = count($characters[0]);
        if ($characterCount > 2_000_000 || count($result->uncertainSpans) > 200_000) {
            throw new OcrResultStorageException('ocr_result_size_invalid');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plain, $nonce, $this->encryptionKey);

        $payload = [
            'p_job_id' => $job->id,
            'p_worker_id' => $job->workerId,
            'p_ciphertext_base64' => base64_encode($ciphertext),
            'p_nonce_base64' => base64_encode($nonce),
            'p_encryption_key_version' => $this->keyVersion,
            'p_detected_language' => $result->detectedLanguage ?? 'und',
            'p_character_count' => $characterCount,
            'p_uncertain_span_count' => count($result->uncertainSpans),
            'p_result_sha256' => hash_hmac('sha256', $plain, $this->encryptionKey),
            'p_plaintext_bytes' => $plainBytes,
        ];

        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/store_document_ocr_result';
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
                if (!function_exists('curl_init')) throw new OcrResultStorageException('ocr_result_store_unavailable');
                $curl = curl_init($url);
                if ($curl === false) throw new OcrResultStorageException('ocr_result_store_unavailable');
                $lines = [];
                foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $error = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $error !== 0) throw new OcrResultStorageException('ocr_result_store_unavailable');
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (OcrResultStorageException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new OcrResultStorageException('ocr_result_store_unavailable');
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new OcrResultStorageException('ocr_result_store_unavailable');
        }

        try {
            $stored = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrResultStorageException('ocr_result_store_invalid_response');
        }

        if ($stored !== true) {
            throw new OcrResultStorageException('ocr_result_lease_lost');
        }
    }
}

final class OcrResultStorageException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Encrypted OCR result storage failed.');
    }
}
