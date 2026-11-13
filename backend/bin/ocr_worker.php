#!/usr/bin/env php
<?php

declare(strict_types=1);

umask(0077);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

if (strtolower(getenv('OCR_WORKER_ENABLED') ?: 'false') !== 'true') {
    fwrite(STDOUT, "OCR worker is disabled.\n");
    exit(0);
}

$base = dirname(__DIR__);
$required = [
    'src/Ocr/OcrRequest.php',
    'src/Ocr/OcrResult.php',
    'src/Ocr/OcrProvider.php',
    'src/Ocr/OcrProviderUnavailableException.php',
    'src/Ocr/OcrJob.php',
    'src/Ocr/OcrJobStore.php',
    'src/Ocr/OcrResultSink.php',
    'src/Ocr/OcrWorker.php',
    'src/Ocr/SupabaseOcrJobStore.php',
    'src/Ocr/SupabaseEncryptedOcrResultSink.php',
    'src/Ocr/GoogleServiceAccountTokenProvider.php',
    'src/Ocr/GoogleCloudVisionOcrProvider.php',
];

foreach ($required as $file) {
    require_once $base . '/' . $file;
}

use Kavoryn\Ocr\GoogleCloudVisionOcrProvider;
use Kavoryn\Ocr\GoogleServiceAccountTokenProvider;
use Kavoryn\Ocr\OcrWorker;
use Kavoryn\Ocr\SupabaseEncryptedOcrResultSink;
use Kavoryn\Ocr\SupabaseOcrJobStore;

$supabaseUrl = getenv('SUPABASE_URL') ?: '';
$secretKey = getenv('SUPABASE_SECRET_KEY') ?: '';
$serviceRoleJwt = getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '';
$storagePath = getenv('PRIVATE_DOCUMENT_STORAGE_PATH') ?: '';
$googleCredentials = getenv('GOOGLE_SERVICE_ACCOUNT_JSON_PATH') ?: '';
$encryptionKey = getenv('OCR_RESULT_ENCRYPTION_KEY') ?: '';
$keyVersion = getenv('OCR_RESULT_ENCRYPTION_KEY_VERSION') ?: '1';

if ($supabaseUrl === '' || $secretKey === '' || $serviceRoleJwt === '' ||
    $storagePath === '' || $googleCredentials === '' || $encryptionKey === '') {
    fwrite(STDERR, "OCR worker configuration is incomplete.\n");
    exit(1);
}

try {
    $jobs = new SupabaseOcrJobStore($supabaseUrl, $secretKey, $serviceRoleJwt);
    $sink = new SupabaseEncryptedOcrResultSink(
        $supabaseUrl,
        $secretKey,
        $serviceRoleJwt,
        SupabaseEncryptedOcrResultSink::decodeKey($encryptionKey),
        $keyVersion,
    );
    $tokens = new GoogleServiceAccountTokenProvider($googleCredentials);
    $provider = new GoogleCloudVisionOcrProvider(
        $tokens,
        getenv('PDFINFO_BINARY') ?: 'pdfinfo',
        getenv('PDFTOPPM_BINARY') ?: 'pdftoppm',
        null,
        getenv('GOOGLE_VISION_ENDPOINT') ?: 'https://eu-vision.googleapis.com/v1/images:annotate',
    );
    $worker = new OcrWorker($jobs, $provider, $sink, $storagePath);

    $maxJobs = (int) (getenv('OCR_WORKER_MAX_JOBS') ?: 3);
    $maxJobs = max(1, min(10, $maxJobs));
    $workerId = 'worker-' . bin2hex(random_bytes(12));
    $processed = 0;

    while ($processed < $maxJobs && $worker->processOne($workerId)) {
        $processed++;
    }

    fwrite(STDOUT, $processed === 0
        ? "No OCR jobs available.\n"
        : "OCR worker processed {$processed} job(s).\n");
    exit(0);
} catch (Throwable $error) {
    // Deliberately omit exception messages: they may contain provider or request details.
    error_log('Kavoryn OCR worker failed: ' . get_class($error));
    fwrite(STDERR, "OCR worker failed safely. Check server logs using the request time.\n");
    exit(1);
}
