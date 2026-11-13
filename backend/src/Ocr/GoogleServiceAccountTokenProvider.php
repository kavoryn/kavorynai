<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final class GoogleServiceAccountTokenProvider
{
    private static array $cache = [];

    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $credentialsPath,
        private readonly mixed $http = null,
    ) {
        if ($credentialsPath === '' || !str_starts_with($credentialsPath, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('An absolute Google service-account credentials path is required.');
        }
    }

    public function accessToken(): string
    {
        $path = realpath($this->credentialsPath);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new OcrProviderUnavailableException('Google OCR credentials are unavailable.');
        }

        $mode = fileperms($path);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new OcrProviderUnavailableException('Google OCR credentials file permissions cannot be verified safely.');
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) throw new OcrProviderUnavailableException();
        try {
            $credentials = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrProviderUnavailableException();
        }

        $email = $credentials['client_email'] ?? null;
        $privateKey = $credentials['private_key'] ?? null;
        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($privateKey) || !is_string($tokenUri) ||
            parse_url($tokenUri, PHP_URL_SCHEME) !== 'https' ||
            parse_url($tokenUri, PHP_URL_HOST) !== 'oauth2.googleapis.com') {
            throw new OcrProviderUnavailableException();
        }

        $cacheKey = hash('sha256', $email . "\0" . $privateKey);
        $cached = self::$cache[$cacheKey] ?? null;
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['token'];
        }

        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'iss' => $email,
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header . '.' . $claims;

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new OcrProviderUnavailableException();
        }
        $assertion = $unsigned . '.' . self::base64Url($signature);

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        try {
            if ($this->http !== null) {
                $response = ($this->http)($tokenUri, [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ], $body);
            } else {
                if (!function_exists('curl_init')) throw new OcrProviderUnavailableException();
                $curl = curl_init($tokenUri);
                if ($curl === false) throw new OcrProviderUnavailableException();
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
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
                if ($responseBody === false || $errno !== 0) throw new OcrProviderUnavailableException();
                $response = ['status' => $status, 'body' => (string) $responseBody];
            }
        } catch (OcrProviderUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new OcrProviderUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new OcrProviderUnavailableException();
        }

        try {
            $tokenData = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrProviderUnavailableException();
        }

        $token = $tokenData['access_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? null;
        if (!is_string($token) || $token === '' || !is_numeric($expiresIn) ||
            (int) $expiresIn < 60 || (int) $expiresIn > 7200) {
            throw new OcrProviderUnavailableException();
        }

        self::$cache[$cacheKey] = [
            'token' => $token,
            'expires_at' => $now + (int) $expiresIn,
        ];
        return $token;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
