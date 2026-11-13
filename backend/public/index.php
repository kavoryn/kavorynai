<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$requestId = bin2hex(random_bytes(12));
header('X-Request-Id: ' . $requestId);

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && ($path === '/health' || $path === '/v1/health')) {
    respond(200, [
        'status' => 'ok',
        'service' => 'kavoryn-api',
        'request_id' => $requestId,
    ]);
}

if ($method === 'GET' && $path === '/v1/me') {
    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    $publishableKey = getenv('SUPABASE_PUBLISHABLE_KEY') ?: '';

    if ($supabaseUrl === '' || $publishableKey === '') {
        respond(503, [
            'error' => [
                'code' => 'authentication_not_configured',
                'message' => 'User verification is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    require_once dirname(__DIR__) . '/src/Auth/AuthenticatedUser.php';
    require_once dirname(__DIR__) . '/src/Auth/AuthenticationException.php';
    require_once dirname(__DIR__) . '/src/Auth/SupabaseUserVerifier.php';

    try {
        $verifier = new Kavoryn\Auth\SupabaseUserVerifier(
            $supabaseUrl,
            $publishableKey,
        );
        $user = $verifier->verifyBearerToken($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    } catch (\Kavoryn\Auth\AuthenticationException) {
        respond(401, [
            'error' => [
                'code' => 'unauthorized',
                'message' => 'A valid sign-in session is required.',
                'request_id' => $requestId,
            ],
        ]);
    }

    respond(200, [
        'authenticated' => true,
        'user_id' => $user->id,
        'request_id' => $requestId,
    ]);
}

respond(404, [
    'error' => [
        'code' => 'not_found',
        'message' => 'The requested endpoint was not found.',
        'request_id' => $requestId,
    ],
]);
