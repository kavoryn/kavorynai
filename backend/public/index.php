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

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 8192) {
        respond(413, [
            'error' => [
                'code' => 'request_too_large',
                'message' => 'The consent request is too large.',
                'request_id' => $requestId,
            ],
        ]);
    }

    try {
        $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
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


if ($method === 'POST' && $path === '/v1/documents') {
    $maxRequestBytes = 21 * 1024 * 1024;
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxRequestBytes) {
        respond(413, [
            'error' => [
                'code' => 'file_too_large',
                'message' => 'The uploaded document exceeds the allowed size.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !is_string($upload['tmp_name'] ?? null) ||
        !is_string($upload['name'] ?? null) || !is_int($upload['error'] ?? null)) {
        respond(400, [
            'error' => [
                'code' => 'file_required',
                'message' => 'Choose a PDF, JPEG, PNG, or WebP document.',
                'request_id' => $requestId,
            ],
        ]);
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        respond($tooLarge ? 413 : 400, [
            'error' => [
                'code' => $tooLarge ? 'file_too_large' : 'upload_failed',
                'message' => $tooLarge
                    ? 'The uploaded document exceeds the server size limit.'
                    : 'The document could not be received. Please try again.',
                'request_id' => $requestId,
            ],
        ]);
    }

    if (!is_uploaded_file($upload['tmp_name'])) {
        respond(400, [
            'error' => [
                'code' => 'invalid_upload_source',
                'message' => 'The uploaded file could not be verified.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $consentToken = $_POST['consent_token'] ?? null;
    $language = $_POST['output_language'] ?? null;
    $translateRaw = $_POST['translate'] ?? null;
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if (!is_string($consentToken) || !is_string($language) ||
        !is_string($translateRaw) || !in_array($translateRaw, ['true', 'false'], true) ||
        !is_string($idempotencyKey)) {
        respond(422, [
            'error' => [
                'code' => 'invalid_upload_request',
                'message' => 'The upload request is incomplete or invalid.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $storagePath = getenv('PRIVATE_DOCUMENT_STORAGE_PATH') ?: '';
    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    if ($storagePath === '' || $secretKey === '' || $serviceRoleJwt === '' || $supabaseUrl === '') {
        respond(503, [
            'error' => [
                'code' => 'upload_storage_not_configured',
                'message' => 'Secure document upload is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
    require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentFileInspector.php';
    require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentRegistration.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentRegistry.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentRegistryUnavailableException.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentConsentRejectedException.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentIdempotencyConflictException.php';
    require_once dirname(__DIR__) . '/src/Upload/SupabaseDocumentRegistry.php';
    require_once dirname(__DIR__) . '/src/Upload/DocumentUploadService.php';

    try {
        $service = new Kavoryn\Upload\DocumentUploadService(
            new Kavoryn\Upload\DocumentFileInspector(),
            new Kavoryn\Upload\PrivateDocumentStorage($storagePath, __DIR__),
            new Kavoryn\Upload\SupabaseDocumentRegistry($supabaseUrl, $secretKey, $serviceRoleJwt),
        );
        $registration = $service->upload(
            $user->id,
            $upload['tmp_name'],
            $upload['name'],
            $consentToken,
            $language,
            $translateRaw === 'true',
            $idempotencyKey,
        );
    } catch (\Kavoryn\Upload\DocumentFileException $error) {
        $status = in_array($error->errorCode, ['file_too_large'], true) ? 413 : 422;
        if (in_array($error->errorCode, ['storage_unavailable', 'storage_not_configured', 'storage_permissions_failed', 'storage_write_failed', 'unsafe_storage_path', 'server_configuration_error'], true)) {
            $status = 503;
        }
        respond($status, [
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Upload\DocumentConsentRejectedException) {
        respond(409, [
            'error' => [
                'code' => 'consent_invalid',
                'message' => 'Your processing confirmation has expired or cannot be used. Please review and confirm again.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Upload\DocumentIdempotencyConflictException) {
        respond(409, [
            'error' => [
                'code' => 'idempotency_conflict',
                'message' => 'This retry key belongs to a different upload. Start a new upload attempt.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Upload\DocumentRegistryUnavailableException) {
        respond(503, [
            'error' => [
                'code' => 'document_registration_unavailable',
                'message' => 'Secure document upload is temporarily unavailable. You can safely retry with the same idempotency key.',
                'request_id' => $requestId,
            ],
        ]);
    }

    respond(201, [
        'document_id' => $registration->id,
        'status' => $registration->status,
        'created_at' => $registration->createdAt->format(DATE_ATOM),
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
