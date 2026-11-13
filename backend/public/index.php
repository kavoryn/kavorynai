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

function authenticatedUser(string $supabaseUrl, string $publishableKey, string $requestId): \Kavoryn\Auth\AuthenticatedUser
{
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
    require_once dirname(__DIR__) . '/src/Auth/AuthenticationServiceUnavailableException.php';
    require_once dirname(__DIR__) . '/src/Auth/SupabaseUserVerifier.php';

    try {
        $verifier = new \Kavoryn\Auth\SupabaseUserVerifier($supabaseUrl, $publishableKey);
        return $verifier->verifyBearerToken($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    } catch (\Kavoryn\Auth\AuthenticationServiceUnavailableException) {
        respond(503, [
            'error' => [
                'code' => 'authentication_service_unavailable',
                'message' => 'User verification is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Auth\AuthenticationException) {
        respond(401, [
            'error' => [
                'code' => 'unauthorized',
                'message' => 'A valid sign-in session is required.',
                'request_id' => $requestId,
            ],
        ]);
    }
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
    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    respond(200, [
        'authenticated' => true,
        'user_id' => $user->id,
        'request_id' => $requestId,
    ]);
}

if ($method === 'POST' && $path === '/v1/consents') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respond(413, [
            'error' => [
                'code' => 'request_too_large',
                'message' => 'The consent request is too large.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        respond(415, [
            'error' => [
                'code' => 'unsupported_content_type',
                'message' => 'Send the consent confirmation as JSON.',
                'request_id' => $requestId,
            ],
        ]);
    }

    try {
        $input = json_decode(file_get_contents('php://input') ?: '', true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        respond(400, [
            'error' => [
                'code' => 'invalid_json',
                'message' => 'The consent request is not valid JSON.',
                'request_id' => $requestId,
            ],
        ]);
    }

    if (!is_array($input) ||
        !is_string($input['action'] ?? null) ||
        !is_string($input['payload_sha256'] ?? null) ||
        !is_string($input['language'] ?? null) ||
        !is_bool($input['translate'] ?? null) ||
        !is_bool($input['confirmed'] ?? null)) {
        respond(422, [
            'error' => [
                'code' => 'invalid_consent_request',
                'message' => 'The consent confirmation is incomplete or invalid.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    if ($secretKey === '' || $serviceRoleJwt === '') {
        respond(503, [
            'error' => [
                'code' => 'consent_storage_not_configured',
                'message' => 'Consent verification is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    require_once dirname(__DIR__) . '/src/Consent/ConsentRecord.php';
    require_once dirname(__DIR__) . '/src/Consent/ConsentGrant.php';
    require_once dirname(__DIR__) . '/src/Consent/ConsentStore.php';
    require_once dirname(__DIR__) . '/src/Consent/ConsentException.php';
    require_once dirname(__DIR__) . '/src/Consent/ConsentStorageUnavailableException.php';
    require_once dirname(__DIR__) . '/src/Consent/SupabaseConsentStore.php';
    require_once dirname(__DIR__) . '/src/Consent/ConsentService.php';

    try {
        $ttl = (int) (getenv('CONSENT_TTL_SECONDS') ?: 300);
        $store = new \Kavoryn\Consent\SupabaseConsentStore(
            getenv('SUPABASE_URL') ?: '',
            $secretKey,
            $serviceRoleJwt,
        );
        $service = new \Kavoryn\Consent\ConsentService($store, $ttl);
        $grant = $service->issue(
            $user->id,
            $input['action'],
            $input['payload_sha256'],
            $input['language'],
            $input['translate'],
            $input['confirmed'],
        );
    } catch (\Kavoryn\Consent\ConsentException) {
        respond(422, [
            'error' => [
                'code' => 'consent_confirmation_required',
                'message' => 'Confirm the document-processing consent to continue.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Consent\ConsentStorageUnavailableException) {
        respond(503, [
            'error' => [
                'code' => 'consent_storage_unavailable',
                'message' => 'Consent verification is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\InvalidArgumentException) {
        respond(503, [
            'error' => [
                'code' => 'consent_configuration_invalid',
                'message' => 'Consent verification is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    respond(201, [
        'consent_id' => $grant->id,
        'consent_token' => $grant->token,
        'expires_at' => $grant->expiresAt->format(DATE_ATOM),
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
