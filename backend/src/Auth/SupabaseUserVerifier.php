<?php

declare(strict_types=1);

namespace Kavoryn\Auth;

final class SupabaseUserVerifier
{
    /**
     * @param callable(string, array<string, string>): array{status:int, body:string}|null $http
     */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $publishableKey,
        private readonly mixed $http = null,
    ) {}

    public function verifyBearerToken(?string $authorization): AuthenticatedUser
    {
        if (!is_string($authorization) ||
            !preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/i', trim($authorization), $matches)) {
            throw new AuthenticationException();
        }

        $token = $matches[1];
        if ($token === '' || strlen($token) > 8192) {
            throw new AuthenticationException();
        }
        if (parse_url($this->supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $this->publishableKey === '') {
            throw new AuthenticationServiceUnavailableException();
        }

        $url = rtrim($this->supabaseUrl, '/') . '/auth/v1/user';
        $headers = [
            'apikey' => $this->publishableKey,
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->http !== null
                ? ($this->http)($url, $headers)
                : $this->sendRequest($url, $headers);
        } catch (\Throwable) {
            throw new AuthenticationServiceUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null)) {
            throw new AuthenticationServiceUnavailableException();
        }

        if ($response['status'] >= 500 || $response['status'] === 0) {
            throw new AuthenticationServiceUnavailableException();
        }

        if ($response['status'] !== 200) {
            throw new AuthenticationException();
        }

        try {
            $user = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthenticationServiceUnavailableException();
        }

        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if (!is_string($id) ||
            !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new AuthenticationServiceUnavailableException();
        }

        $email = is_array($user) && is_string($user['email'] ?? null)
            ? $user['email']
            : null;

        return new AuthenticatedUser($id, $email);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    private function sendRequest(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialize HTTP client.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
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

        if ($body === false || $error !== 0) {
            throw new \RuntimeException('Authentication service unavailable.');
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
