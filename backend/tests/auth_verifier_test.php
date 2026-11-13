<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Auth/AuthenticatedUser.php';
require_once dirname(__DIR__) . '/src/Auth/AuthenticationException.php';
require_once dirname(__DIR__) . '/src/Auth/AuthenticationServiceUnavailableException.php';
require_once dirname(__DIR__) . '/src/Auth/SupabaseUserVerifier.php';

use Kavoryn\Auth\AuthenticationException;
use Kavoryn\Auth\AuthenticationServiceUnavailableException;
use Kavoryn\Auth\SupabaseUserVerifier;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$validId = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
$seenUrl = '';
$seenHeaders = [];

$verifier = new SupabaseUserVerifier(
    'https://example.supabase.co',
    'sb_publishable_test_key',
    static function (string $url, array $headers) use (&$seenUrl, &$seenHeaders, $validId): array {
        $seenUrl = $url;
        $seenHeaders = $headers;

        return [
            'status' => 200,
            'body' => json_encode(['id' => $validId, 'email' => 'person@example.test'], JSON_THROW_ON_ERROR),
        ];
    },
);

$user = $verifier->verifyBearerToken('Bearer opaque-access-token');
expect($user->id === $validId, 'Verifier must return the Auth-server user ID.');
expect($user->email === 'person@example.test', 'Verifier should preserve optional email.');
expect($seenUrl === 'https://example.supabase.co/auth/v1/user', 'Verifier must call the configured Auth user endpoint.');
expect(($seenHeaders['Authorization'] ?? null) === 'Bearer opaque-access-token', 'Verifier must forward the bearer token.');
expect(($seenHeaders['apikey'] ?? null) === 'sb_publishable_test_key', 'Verifier must use the configured publishable key.');

$rejected = false;
try {
    $verifier->verifyBearerToken(null);
} catch (AuthenticationException) {
    $rejected = true;
}
expect($rejected, 'Missing bearer token must be rejected.');

$invalidUserVerifier = new SupabaseUserVerifier(
    'https://example.supabase.co',
    'sb_publishable_test_key',
    static fn (): array => ['status' => 200, 'body' => '{"id":"not-a-uuid"}'],
);
$rejected = false;
try {
    $invalidUserVerifier->verifyBearerToken('Bearer opaque-access-token');
} catch (AuthenticationServiceUnavailableException) {
    $rejected = true;
}
expect($rejected, 'Malformed Auth response must be treated as an upstream failure.');

$rejectedVerifier = new SupabaseUserVerifier(
    'https://example.supabase.co',
    'sb_publishable_test_key',
    static fn (): array => ['status' => 401, 'body' => '{}'],
);
$rejected = false;
try {
    $rejectedVerifier->verifyBearerToken('Bearer expired-token');
} catch (AuthenticationException) {
    $rejected = true;
}
expect($rejected, 'Auth server rejection must be rejected.');

$unavailableVerifier = new SupabaseUserVerifier(
    'https://example.supabase.co',
    'sb_publishable_test_key',
    static fn (): array => ['status' => 503, 'body' => '{}'],
);
$unavailable = false;
try {
    $unavailableVerifier->verifyBearerToken('Bearer opaque-access-token');
} catch (AuthenticationServiceUnavailableException) {
    $unavailable = true;
}
expect($unavailable, 'Auth provider outages must be distinguished from invalid sessions.');

$misconfiguredVerifier = new SupabaseUserVerifier(
    'http://example.supabase.co',
    'sb_publishable_test_key',
    static fn (): array => ['status' => 200, 'body' => json_encode(['id' => $validId], JSON_THROW_ON_ERROR)],
);
$unavailable = false;
try {
    $misconfiguredVerifier->verifyBearerToken('Bearer opaque-access-token');
} catch (AuthenticationServiceUnavailableException) {
    $unavailable = true;
}
expect($unavailable, 'Non-HTTPS Supabase configuration must fail closed as a server configuration error.');

echo "Authentication verifier tests passed." . PHP_EOL;
