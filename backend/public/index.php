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

if ($method === 'POST' && $path === '/v1/webhooks/paystack') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 1048576) {
        respond(413, ['error'=>['code'=>'webhook_too_large','message'=>'The webhook payload is too large.','request_id'=>$requestId]]);
    }

    $inputStream = fopen('php://input', 'rb');
    $rawBody = $inputStream === false ? false : stream_get_contents($inputStream, 1048577);
    if (is_resource($inputStream)) {
        fclose($inputStream);
    }
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1048576) {
        respond(400, ['error'=>['code'=>'invalid_webhook','message'=>'The webhook payload is invalid.','request_id'=>$requestId]]);
    }

    $paystackSecret = getenv('PAYSTACK_SECRET_KEY') ?: '';
    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    $supabaseSecret = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    if ($paystackSecret === '' || $supabaseUrl === '' || $supabaseSecret === '' || $serviceRoleJwt === '') {
        respond(503, ['error'=>['code'=>'webhook_not_configured','message'=>'Payment notification processing is temporarily unavailable.','request_id'=>$requestId]]);
    }

    require_once dirname(__DIR__) . '/src/Billing/PaystackWebhookVerifier.php';
    require_once dirname(__DIR__) . '/src/Billing/SupabasePaystackWebhookStore.php';

    try {
        $verifier = new \Kavoryn\\Billing\\PaystackWebhookVerifier($paystackSecret);
        $event = $verifier->verify($rawBody, $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null);
    } catch (\Kavoryn\\Billing\\InvalidPaystackWebhookException) {
        respond(401, ['error'=>['code'=>'invalid_webhook_signature','message'=>'The payment notification could not be authenticated.','request_id'=>$requestId]]);
    }

    $eventReference = $event['data']['reference'] ?? null;
    if (!is_string($eventReference) || $eventReference === '' || strlen($eventReference) > 120) {
        $eventReference = null;
    }

    try {
        $store = new \Kavoryn\\Billing\\SupabasePaystackWebhookStore(
            $supabaseUrl,
            $supabaseSecret,
            $serviceRoleJwt,
        );
        $receiptBytes = random_bytes(16);
        $receiptBytes[6] = chr((ord($receiptBytes[6]) & 0x0f) | 0x40);
        $receiptBytes[8] = chr((ord($receiptBytes[8]) & 0x3f) | 0x80);
        $receiptHex = bin2hex($receiptBytes);
        $receiptId = sprintf(
            '%s-%s-%s-%s-%s',
            substr($receiptHex, 0, 8),
            substr($receiptHex, 8, 4),
            substr($receiptHex, 12, 4),
            substr($receiptHex, 16, 4),
            substr($receiptHex, 20, 12),
        );
        $created = $store->recordVerifiedReceipt(
            $receiptId,
            hash('sha256', $rawBody),
            $event['event'],
            $eventReference,
        );
    } catch (\\Throwable) {
        // A non-2xx response asks Paystack to retry; do not acknowledge a lost receipt.
        respond(503, ['error'=>['code'=>'webhook_storage_unavailable','message'=>'Payment notification processing is temporarily unavailable.','request_id'=>$requestId]]);
    }

    respond(200, [
        'received' => true,
        'duplicate' => !$created,
        'request_id' => $requestId,
    ]);
}

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
    if (strtolower(getenv('DOCUMENT_UPLOAD_ENABLED') ?: 'false') !== 'true') {
        respond(503, [
            'error' => [
                'code' => 'document_upload_disabled',
                'message' => 'Secure document upload is not enabled on this server yet.',
                'request_id' => $requestId,
            ],
        ]);
    }

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
        $rateLimiter = new Kavoryn\Upload\SupabaseDocumentRateLimiter(
            $supabaseUrl,
            $secretKey,
            $serviceRoleJwt,
        );
        $rateLimiter->consume($user->id);

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
    } catch (\Kavoryn\Upload\DocumentRateLimitExceededException $error) {
        header('Retry-After: ' . $error->retryAfterSeconds);
        respond(429, [
            'error' => [
                'code' => 'upload_rate_limited',
                'message' => 'You have reached the document upload limit. Please wait before trying again.',
                'retry_after_seconds' => $error->retryAfterSeconds,
                'request_id' => $requestId,
            ],
        ]);
    } catch (\Kavoryn\Upload\DocumentRateLimiterUnavailableException) {
        respond(503, [
            'error' => [
                'code' => 'upload_rate_limit_unavailable',
                'message' => 'Secure document upload is temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
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

if ($method === 'POST' && preg_match(
    '#^/v1/documents/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/explain$#i',
    $path,
    $matches,
)) {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respond(413, ['error' => ['code' => 'request_too_large', 'message' => 'The explanation request is too large.', 'request_id' => $requestId]]);
    }

    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        respond(415, ['error' => ['code' => 'unsupported_content_type', 'message' => 'Send the AI processing consent as JSON.', 'request_id' => $requestId]]);
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 8192) {
        respond(413, ['error' => ['code' => 'request_too_large', 'message' => 'The explanation request is too large.', 'request_id' => $requestId]]);
    }
    try {
        $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        respond(400, ['error' => ['code' => 'invalid_json', 'message' => 'The explanation request is not valid JSON.', 'request_id' => $requestId]]);
    }

    if (!is_array($input) || ($input['confirmed'] ?? null) !== true ||
        !is_string($input['provider'] ?? null) ||
        !is_string($input['language'] ?? null) ||
        !in_array($input['provider'], ['openai', 'gemini'], true) ||
        !in_array($input['language'], ['en', 'ha', 'ar', 'fr'], true)) {
        respond(422, [
            'error' => [
                'code' => 'ai_processing_consent_required',
                'message' => 'Review the AI processing notice and explicitly confirm the selected provider and output language.',
                'request_id' => $requestId,
            ],
        ]);
    }

    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $encryptionKey = getenv('OCR_RESULT_ENCRYPTION_KEY') ?: '';
    if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '' || $encryptionKey === '') {
        respond(503, ['error' => ['code' => 'document_explanation_not_configured', 'message' => 'Document explanation is temporarily unavailable.', 'request_id' => $requestId]]);
    }

    require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
    require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultReader.php';
    require_once dirname(__DIR__) . '/src/Ai/DocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/DocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/DocumentExplainerFactory.php';
    require_once dirname(__DIR__) . '/src/Ai/SupabaseAiProcessingConsentRecorder.php';

    try {
        $reader = new \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader(
            $supabaseUrl,
            $secretKey,
            $serviceRoleJwt,
            \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader::decodeKey($encryptionKey),
        );
        $ocrResult = $reader->read($matches[1], $user->id);

        $explainer = \Kavoryn\Ai\DocumentExplainerFactory::create($input['provider'], [
            'OPENAI_API_KEY' => getenv('OPENAI_API_KEY') ?: '',
            'OPENAI_MODEL' => getenv('OPENAI_MODEL') ?: 'gpt-5-mini',
            'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: '',
            'GEMINI_MODEL' => getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash',
        ]);

        $consentRecorder = new \Kavoryn\Ai\SupabaseAiProcessingConsentRecorder(
            $supabaseUrl,
            $secretKey,
            $serviceRoleJwt,
        );
        // Persist the user's explicit provider-specific consent before sending OCR text externally.
        $consentRecorder->record($user->id, $matches[1], $input['provider'], $input['language']);

        $explanation = $explainer->explain($ocrResult, $input['language']);
    } catch (\Kavoryn\Ocr\OcrResultNotFoundException) {
        respond(404, ['error' => ['code' => 'document_result_not_found', 'message' => 'A completed document result is not available or has expired.', 'request_id' => $requestId]]);
    } catch (\Kavoryn\Ai\AiConsentStorageUnavailableException) {
        respond(503, ['error' => ['code' => 'ai_consent_storage_unavailable', 'message' => 'AI processing consent could not be recorded. No document text was sent to the AI provider.', 'request_id' => $requestId]]);
    } catch (\Kavoryn\Ai\OpenAiUnavailableException | \Kavoryn\Ai\GeminiUnavailableException) {
        respond(503, ['error' => ['code' => 'ai_provider_unavailable', 'message' => 'The selected AI provider could not complete the explanation. No explanation was saved.', 'request_id' => $requestId]]);
    } catch (\InvalidArgumentException | \Kavoryn\Ocr\OcrResultReaderUnavailableException) {
        respond(503, ['error' => ['code' => 'document_explanation_unavailable', 'message' => 'Document explanation is temporarily unavailable.', 'request_id' => $requestId]]);
    }

    respond(200, [
        'document_id' => $matches[1],
        'provider' => $input['provider'],
        'language' => $input['language'],
        'summary' => $explanation['summary'],
        'detailed_explanation' => $explanation['detailed_explanation'],
        'actions' => $explanation['actions'],
        'key_facts' => $explanation['key_facts'],
        'request_id' => $requestId,
    ]);
}


if ($method === 'POST' && preg_match(
    '#^/v1/documents/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/ask$#i',
    $path,
    $matches,
)) {
    if (strtolower(getenv('DOCUMENT_QA_ENABLED') ?: 'false') !== 'true') {
        respond(503, ['error' => ['code' => 'document_qa_disabled', 'message' => 'Document questions are not enabled on this server yet.', 'request_id' => $requestId]]);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respond(413, ['error' => ['code' => 'request_too_large', 'message' => 'The question request is too large.', 'request_id' => $requestId]]);
    }
    $user = authenticatedUser(getenv('SUPABASE_URL') ?: '', getenv('SUPABASE_PUBLISHABLE_KEY') ?: '', $requestId);
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        respond(415, ['error' => ['code' => 'unsupported_content_type', 'message' => 'Send the question as JSON.', 'request_id' => $requestId]]);
    }
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 8192) {
        respond(413, ['error' => ['code' => 'request_too_large', 'message' => 'The question request is too large.', 'request_id' => $requestId]]);
    }
    try { $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { respond(400, ['error' => ['code' => 'invalid_json', 'message' => 'The question request is not valid JSON.', 'request_id' => $requestId]]); }
    if (!is_array($input) || ($input['confirmed'] ?? null) !== true ||
        !is_string($input['provider'] ?? null) || !in_array($input['provider'], ['openai', 'gemini'], true) ||
        !is_string($input['language'] ?? null) || !in_array($input['language'], ['en', 'ha', 'ar', 'fr'], true) ||
        !is_string($input['question'] ?? null)) {
        respond(422, ['error' => ['code' => 'ai_question_consent_required', 'message' => 'Confirm the selected AI provider and enter a valid question.', 'request_id' => $requestId]]);
    }
    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $encryptionKey = getenv('OCR_RESULT_ENCRYPTION_KEY') ?: '';
    if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '' || $encryptionKey === '') {
        respond(503, ['error' => ['code' => 'document_qa_not_configured', 'message' => 'Document questions are temporarily unavailable.', 'request_id' => $requestId]]);
    }

    // These existing provider files declare the shared provider-unavailable exception classes.
    require_once dirname(__DIR__) . '/src/Ai/DocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentExplainer.php';
    require_once dirname(__DIR__) . '/src/Ai/GroundedQuestionPolicy.php';
    require_once dirname(__DIR__) . '/src/Ai/DocumentQuestionAnswerer.php';
    require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentQuestionAnswerer.php';
    require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentQuestionAnswerer.php';
    require_once dirname(__DIR__) . '/src/Ai/DocumentQuestionAnswererFactory.php';
    require_once dirname(__DIR__) . '/src/Ai/SupabaseAiProcessingConsentRecorder.php';
    require_once dirname(__DIR__) . '/src/Ai/SupabaseAiQuestionRateLimiter.php';
    require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
    require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultReader.php';

    try {
        $question = \Kavoryn\Ai\GroundedQuestionPolicy::validateQuestion($input['question']);
    } catch (\InvalidArgumentException) {
        respond(422, ['error' => ['code' => 'invalid_question', 'message' => 'The question must contain between 1 and 1,000 valid characters.', 'request_id' => $requestId]]);
    }

    try {
        $limiter = new \Kavoryn\Ai\SupabaseAiQuestionRateLimiter($supabaseUrl, $secretKey, $serviceRoleJwt);
        if (!$limiter->allow($user->id)) {
            header('Retry-After: 60');
            respond(429, ['error' => ['code' => 'rate_limited', 'message' => 'You have reached the document question limit. Please try again shortly.', 'request_id' => $requestId]]);
        }
        $reader = new \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader(
            $supabaseUrl, $secretKey, $serviceRoleJwt,
            \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader::decodeKey($encryptionKey),
        );
        $ocrResult = $reader->read($matches[1], $user->id);
        $consentRecorder = new \Kavoryn\Ai\SupabaseAiProcessingConsentRecorder($supabaseUrl, $secretKey, $serviceRoleJwt);
        // Persist distinct Q&A consent before any OCR text is sent to an external provider.
        $consentRecorder->record($user->id, $matches[1], $input['provider'], $input['language'], 'ask');
        $answerer = \Kavoryn\Ai\DocumentQuestionAnswererFactory::create($input['provider'], [
            'OPENAI_API_KEY' => getenv('OPENAI_API_KEY') ?: '',
            'OPENAI_MODEL' => getenv('OPENAI_MODEL') ?: 'gpt-5-mini',
            'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: '',
            'GEMINI_MODEL' => getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash',
        ]);
        $answer = $answerer->answer($ocrResult, $question, $input['language']);
    } catch (\Kavoryn\Ocr\OcrResultNotFoundException) {
        respond(404, ['error' => ['code' => 'document_result_not_found', 'message' => 'A completed document result is not available or has expired.', 'request_id' => $requestId]]);
    } catch (\Kavoryn\Ai\AiConsentStorageUnavailableException) {
        respond(503, ['error' => ['code' => 'ai_consent_storage_unavailable', 'message' => 'AI processing consent could not be recorded. No document text was sent to the AI provider.', 'request_id' => $requestId]]);
    } catch (\Kavoryn\Ai\AiQuestionRateLimitUnavailableException) {
        respond(503, ['error' => ['code' => 'rate_limit_unavailable', 'message' => 'Document questions are temporarily unavailable.', 'request_id' => $requestId]]);
    } catch (\Kavoryn\Ai\OpenAiUnavailableException | \Kavoryn\Ai\GeminiUnavailableException) {
        respond(503, ['error' => ['code' => 'ai_provider_unavailable', 'message' => 'The selected AI provider could not answer this question.', 'request_id' => $requestId]]);
    } catch (\InvalidArgumentException | \Kavoryn\Ocr\OcrResultReaderUnavailableException) {
        respond(503, ['error' => ['code' => 'document_qa_unavailable', 'message' => 'Document questions are temporarily unavailable.', 'request_id' => $requestId]]);
    }

    respond(200, [
        'document_id' => $matches[1],
        'provider' => $input['provider'],
        'language' => $input['language'],
        'answer' => $answer['answer'],
        'source_excerpts' => $answer['source_excerpts'],
        'insufficient_information' => $answer['insufficient_information'],
        'request_id' => $requestId,
    ]);
}

if ($method === 'GET' && preg_match(
    '#^/v1/documents/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/result$#i',
    $path,
    $matches,
)) {
    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $encryptionKey = getenv('OCR_RESULT_ENCRYPTION_KEY') ?: '';
    if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '' || $encryptionKey === '') {
        respond(503, [
            'error' => [
                'code' => 'ocr_result_storage_not_configured',
                'message' => 'Document results are temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
    require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultReader.php';

    try {
        $reader = new \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader(
            $supabaseUrl,
            $secretKey,
            $serviceRoleJwt,
            \Kavoryn\Ocr\SupabaseEncryptedOcrResultReader::decodeKey($encryptionKey),
        );
        $result = $reader->read($matches[1], $user->id);
    } catch (\Kavoryn\Ocr\OcrResultNotFoundException) {
        respond(404, [
            'error' => [
                'code' => 'document_result_not_found',
                'message' => 'The document result is not available or has expired.',
                'request_id' => $requestId,
            ],
        ]);
    } catch (\InvalidArgumentException | \Kavoryn\Ocr\OcrResultReaderUnavailableException) {
        respond(503, [
            'error' => [
                'code' => 'document_result_unavailable',
                'message' => 'Document results are temporarily unavailable.',
                'request_id' => $requestId,
            ],
        ]);
    }

    respond(200, [
        'document_id' => $matches[1],
        'text' => $result->text,
        'uncertain_spans' => $result->uncertainSpans,
        'detected_language' => $result->detectedLanguage ?? 'und',
        'request_id' => $requestId,
    ]);
}

// General-purpose chat API. Disabled by default until staging validation and release gates pass.
if (str_starts_with($path, '/v1/chat/')) {
    if (strtolower(getenv('CHAT_ENABLED') ?: 'false') !== 'true') {
        respond(503, ['error'=>['code'=>'chat_disabled','message'=>'Kavoryn Chat is not enabled on this server yet.','request_id'=>$requestId]]);
    }

    $user = authenticatedUser(
        getenv('SUPABASE_URL') ?: '',
        getenv('SUPABASE_PUBLISHABLE_KEY') ?: '',
        $requestId,
    );

    $secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
    $serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
    $supabaseUrl = getenv('SUPABASE_URL') ?: '';
    if ($secretKey === '' || $serviceRoleJwt === '' || $supabaseUrl === '') {
        respond(503, ['error'=>['code'=>'chat_storage_not_configured','message'=>'Chat is temporarily unavailable.','request_id'=>$requestId]]);
    }

    require_once dirname(__DIR__) . '/src/Ai/SupabaseChatStore.php';
    try {
        $store = new \Kavoryn\Ai\SupabaseChatStore($supabaseUrl, $secretKey, $serviceRoleJwt);
    } catch (\InvalidArgumentException) {
        respond(503, ['error'=>['code'=>'chat_storage_not_configured','message'=>'Chat is temporarily unavailable.','request_id'=>$requestId]]);
    }

    $readJson = static function (int $maxBytes = 8192) use ($requestId): array {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            respond(413, ['error'=>['code'=>'request_too_large','message'=>'The chat request is too large.','request_id'=>$requestId]]);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            respond(415, ['error'=>['code'=>'unsupported_content_type','message'=>'Send chat data as JSON.','request_id'=>$requestId]]);
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > $maxBytes) {
            respond(413, ['error'=>['code'=>'request_too_large','message'=>'The chat request is too large.','request_id'=>$requestId]]);
        }
        try { $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) {
            respond(400, ['error'=>['code'=>'invalid_json','message'=>'The chat request is not valid JSON.','request_id'=>$requestId]]);
        }
        if (!is_array($data) || array_is_list($data)) {
            respond(422, ['error'=>['code'=>'invalid_chat_request','message'=>'The chat request is invalid.','request_id'=>$requestId]]);
        }
        return $data;
    };


    if (preg_match('#^/v1/chat/conversations/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/messages$#i', $path, $messageMatch) === 1 && $method === 'POST') {
        $input = $readJson(100000);
        $messageText = $input['message'] ?? null;
        if (!is_string($messageText) || trim($messageText) === '' ||
            strlen($messageText) > 96000 || preg_match('/\A.{1,24000}\z/us', $messageText) !== 1) {
            respond(422, ['error'=>['code'=>'invalid_chat_message','message'=>'Enter a message within the allowed size.','request_id'=>$requestId]]);
        }

        $conversationId = $messageMatch[1];
        $conversation = $store->getConversation($user->id, $conversationId);
        if ($conversation === null) {
            respond(404, ['error'=>['code'=>'conversation_not_found','message'=>'The conversation was not found.','request_id'=>$requestId]]);
        }
        $provider = $conversation['provider'] ?? null;
        if (!is_string($provider) || !in_array($provider, ['openai','gemini'], true)) {
            respond(503, ['error'=>['code'=>'chat_provider_unavailable','message'=>'The selected AI provider is temporarily unavailable.','request_id'=>$requestId]]);
        }
        if (!$store->hasConsent($user->id, $provider)) {
            respond(409, ['error'=>['code'=>'chat_provider_consent_required','message'=>'Confirm provider data processing before sending a message.','request_id'=>$requestId]]);
        }

        require_once dirname(__DIR__) . '/src/Ai/ProviderSseEventMapper.php';
        require_once dirname(__DIR__) . '/src/Ai/ProviderStreamException.php';
        require_once dirname(__DIR__) . '/src/Ai/SseEventDecoder.php';
        require_once dirname(__DIR__) . '/src/Ai/OpenAiSseEventMapper.php';
        require_once dirname(__DIR__) . '/src/Ai/GeminiSseEventMapper.php';
        require_once dirname(__DIR__) . '/src/Ai/ProviderStreamRequest.php';
        require_once dirname(__DIR__) . '/src/Ai/ProviderStreamTransport.php';
        require_once dirname(__DIR__) . '/src/Ai/CurlSseStreamTransport.php';
        require_once dirname(__DIR__) . '/src/Ai/OpenAiResponsesRequestFactory.php';
        require_once dirname(__DIR__) . '/src/Ai/GeminiInteractionsRequestFactory.php';
        require_once dirname(__DIR__) . '/src/Ai/ProviderChatStreamer.php';
        require_once dirname(__DIR__) . '/src/Ai/ProviderChatStreamerFactory.php';

        $providerEnvironment = [
            'OPENAI_API_KEY' => getenv('OPENAI_API_KEY') ?: '',
            'OPENAI_CHAT_MODEL' => getenv('OPENAI_CHAT_MODEL') ?: '',
            'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: '',
            'GEMINI_CHAT_MODEL' => getenv('GEMINI_CHAT_MODEL') ?: '',
        ];
        try {
            $streamer = \Kavoryn\Ai\ProviderChatStreamerFactory::fromEnvironment($providerEnvironment, $provider);
        } catch (\InvalidArgumentException) {
            respond(503, ['error'=>['code'=>'chat_provider_unavailable','message'=>'The selected AI provider is temporarily unavailable.','request_id'=>$requestId]]);
        }

        if (!$store->consumeRateLimit($user->id, 20, 60)) {
            respond(429, ['error'=>['code'=>'chat_rate_limited','message'=>'Please wait before sending another message.','request_id'=>$requestId]]);
        }

        $turnId = $store->newId();
        $store->saveMessage([
            'id'=>$store->newId(), 'user_id'=>$user->id,
            'conversation_id'=>$conversationId, 'turn_id'=>$turnId,
            'role'=>'user', 'content'=>$messageText, 'status'=>'completed',
            'provider'=>$provider,
        ]);

        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        ignore_user_abort(true);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        ob_implicit_flush(true);

        $sendSse = static function (array $payload): void {
            echo 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @ob_flush();
            flush();
        };

        $assistantText = '';
        $assistantCharacters = 0;
        $streamCompleted = false;
        $cancelled = false;
        $outputTooLarge = false;
        $streamFailed = false;
        try {
            $streamer->stream(
                $provider,
                $messageText,
                static function (array $event) use (
                    &$assistantText, &$assistantCharacters, &$streamCompleted, &$outputTooLarge, $sendSse
                ): void {
                    if (($event['type'] ?? null) === 'completed') {
                        $streamCompleted = true;
                        return;
                    }
                    if (($event['type'] ?? null) !== 'delta' || !is_string($event['text'] ?? null)) {
                        return;
                    }
                    $delta = $event['text'];
                    if ($delta === '') { return; }
                    if (preg_match_all('/./us', $delta, $matches) === false) {
                        throw new \RuntimeException('Invalid UTF-8 provider output.');
                    }
                    $deltaCharacters = count($matches[0]);
                    if ($assistantCharacters + $deltaCharacters > 24000 ||
                        strlen($assistantText) + strlen($delta) > 96000) {
                        $outputTooLarge = true;
                        throw new \LengthException('The generated response exceeded the allowed size.');
                    }
                    $assistantCharacters += $deltaCharacters;
                    $assistantText .= $delta;
                    $sendSse(['type'=>'delta','text'=>$delta]);
                },
                static function () use (&$cancelled): bool {
                    if (connection_aborted() === 1) {
                        $cancelled = true;
                        return true;
                    }
                    return false;
                },
            );
            if (!$streamCompleted || trim($assistantText) === '') {
                throw new \Kavoryn\Ai\ProviderStreamException('The provider stream did not complete.');
            }
        } catch (\Throwable) {
            $streamFailed = true;
        }

        $model = $provider === 'openai'
            ? ($providerEnvironment['OPENAI_CHAT_MODEL'] ?: null)
            : ($providerEnvironment['GEMINI_CHAT_MODEL'] ?: null);

        if (!$streamFailed) {
            try {
                $savedAssistant = $store->saveMessage([
                    'id'=>$store->newId(), 'user_id'=>$user->id,
                    'conversation_id'=>$conversationId, 'turn_id'=>$turnId,
                    'role'=>'assistant', 'content'=>$assistantText, 'status'=>'completed',
                    'provider'=>$provider, 'model'=>$model,
                ]);
                $sendSse(['type'=>'completed','message_id'=>$savedAssistant['id']]);
            } catch (\Throwable) {
                $sendSse(['type'=>'error','code'=>'chat_storage_unavailable','message'=>'Your response could not be saved. Please try again.','request_id'=>$requestId]);
            }
            exit;
        }

        $terminalStatus = $cancelled ? 'cancelled' : 'failed';
        $safePlaceholder = $cancelled ? 'The response was cancelled.' : 'The response could not be completed.';
        try {
            $store->saveMessage([
                'id'=>$store->newId(), 'user_id'=>$user->id,
                'conversation_id'=>$conversationId, 'turn_id'=>$turnId,
                'role'=>'assistant', 'content'=>$safePlaceholder, 'status'=>$terminalStatus,
                'provider'=>$provider, 'model'=>$model,
            ]);
        } catch (\Throwable) {
            // Keep the stream error generic; do not expose storage or provider internals.
        }
        if (connection_aborted() !== 1) {
            $code = $cancelled ? 'chat_cancelled' : ($outputTooLarge ? 'chat_response_too_large' : 'chat_provider_error');
            $message = $cancelled ? 'The response was cancelled.' : ($outputTooLarge ? 'The response exceeded the allowed size.' : 'The AI response could not be completed. Please try again.');
            $sendSse(['type'=>'error','code'=>$code,'message'=>$message,'request_id'=>$requestId]);
        }
        exit;
    }

    try {
        if ($path === '/v1/chat/consents' && $method === 'POST') {
            $input = $readJson();
            if (($input['confirmed'] ?? null) !== true ||
                !is_string($input['provider'] ?? null) ||
                !in_array($input['provider'], ['openai','gemini'], true)) {
                respond(422, ['error'=>['code'=>'chat_provider_consent_required','message'=>'Explicitly confirm the selected AI provider before continuing.','request_id'=>$requestId]]);
            }
            $id = $store->recordConsent($user->id, $input['provider'], 'chat-policy-v1');
            respond(201, ['consent_id'=>$id,'provider'=>$input['provider'],'purpose'=>'chat.message','request_id'=>$requestId]);
        }

        if (preg_match('#^/v1/chat/consents/(openai|gemini)$#', $path, $consentMatch) === 1 && $method === 'DELETE') {
            $count = $store->revokeConsent($user->id, $consentMatch[1]);
            respond(200, ['revoked'=>$count > 0,'provider'=>$consentMatch[1],'request_id'=>$requestId]);
        }

        if ($path === '/v1/chat/conversations' && $method === 'POST') {
            $input = $readJson();
            if (!is_string($input['provider'] ?? null) ||
                !is_string($input['language'] ?? null) ||
                !in_array($input['provider'], ['openai','gemini'], true) ||
                !in_array($input['language'], ['en','ha','ar','fr'], true) ||
                (isset($input['title']) && !is_string($input['title']))) {
                respond(422, ['error'=>['code'=>'invalid_conversation','message'=>'Choose a supported provider and language.','request_id'=>$requestId]]);
            }
            if (!$store->hasConsent($user->id, $input['provider'])) {
                respond(409, ['error'=>['code'=>'chat_provider_consent_required','message'=>'Confirm provider data processing before creating a chat.','request_id'=>$requestId]]);
            }
            $conversation = $store->createConversation($user->id, $input['provider'], $input['language'], $input['title'] ?? null);
            respond(201, ['conversation'=>$conversation,'request_id'=>$requestId]);
        }

        if ($path === '/v1/chat/conversations' && $method === 'GET') {
            $limit = filter_var($_GET['limit'] ?? 30, FILTER_VALIDATE_INT);
            if ($limit === false) $limit = 30;
            respond(200, ['conversations'=>$store->listConversations($user->id, $limit),'request_id'=>$requestId]);
        }

        if (preg_match('#^/v1/chat/conversations/([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$#i', $path, $conversationMatch) === 1) {
            $conversationId = $conversationMatch[1];
            if ($method === 'GET') {
                $conversation = $store->getConversation($user->id, $conversationId);
                if ($conversation === null) respond(404, ['error'=>['code'=>'conversation_not_found','message'=>'The conversation was not found.','request_id'=>$requestId]]);
                respond(200, ['conversation'=>$conversation,'request_id'=>$requestId]);
            }
            if ($method === 'DELETE') {
                $deleted = $store->deleteConversation($user->id, $conversationId);
                if (!$deleted) respond(404, ['error'=>['code'=>'conversation_not_found','message'=>'The conversation was not found.','request_id'=>$requestId]]);
                respond(200, ['deleted'=>true,'conversation_id'=>$conversationId,'request_id'=>$requestId]);
            }
        }
    } catch (\Kavoryn\Ai\ChatStorageUnavailableException) {
        respond(503, ['error'=>['code'=>'chat_storage_unavailable','message'=>'Chat is temporarily unavailable. Please try again.','request_id'=>$requestId]]);
    } catch (\InvalidArgumentException) {
        respond(422, ['error'=>['code'=>'invalid_chat_request','message'=>'The chat request is invalid.','request_id'=>$requestId]]);
    }
}

respond(404, [
    'error' => [
        'code' => 'not_found',
        'message' => 'The requested endpoint was not found.',
        'request_id' => $requestId,
    ],
]);
