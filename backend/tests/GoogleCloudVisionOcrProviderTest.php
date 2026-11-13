<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProviderUnavailableException.php';
require_once dirname(__DIR__) . '/src/Ocr/GoogleServiceAccountTokenProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/GoogleCloudVisionOcrProvider.php';

use Kavoryn\Ocr\GoogleCloudVisionOcrProvider;
use Kavoryn\Ocr\GoogleServiceAccountTokenProvider;
use Kavoryn\Ocr\OcrRequest;

function expectVision(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false) throw new RuntimeException('Could not create test signing key.');
openssl_pkey_export($key, $privatePem);
$details = openssl_pkey_get_details($key);
$credentialsPath = sys_get_temp_dir() . '/kavoryn-google-test-' . bin2hex(random_bytes(5)) . '.json';
file_put_contents($credentialsPath, json_encode([
    'type' => 'service_account',
    'client_email' => 'kavoryn-test@example.iam.gserviceaccount.com',
    'private_key' => $privatePem,
    'token_uri' => 'https://oauth2.googleapis.com/token',
], JSON_THROW_ON_ERROR));
chmod($credentialsPath, 0600);

$imagePath = sys_get_temp_dir() . '/kavoryn-vision-test-' . bin2hex(random_bytes(5)) . '.png';
file_put_contents($imagePath, 'test-image-bytes');

$tokenCalls = 0;
$tokenHttp = function (string $url, array $headers, string $body) use (&$tokenCalls): array {
    $tokenCalls++;
    expectVision($url === 'https://oauth2.googleapis.com/token', 'Unexpected OAuth token endpoint.');
    expectVision(str_contains($body, 'urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer'), 'Expected JWT bearer OAuth grant.');
    return ['status' => 200, 'body' => json_encode(['access_token' => 'test-access-token', 'expires_in' => 3600])];
};
$tokens = new GoogleServiceAccountTokenProvider($credentialsPath, $tokenHttp);

$visionCalls = 0;
$visionHttp = function (string $url, array $headers, string $body) use (&$visionCalls): array {
    $visionCalls++;
    expectVision($url === 'https://eu-vision.googleapis.com/v1/images:annotate', 'Unexpected Vision endpoint.');
    expectVision(($headers['Authorization'] ?? '') === 'Bearer test-access-token', 'Vision request must use server OAuth token.');
    $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    expectVision(($request['requests'][0]['features'][0]['type'] ?? null) === 'DOCUMENT_TEXT_DETECTION', 'Dense document OCR feature missing.');
    expectVision(!isset($request['requests'][0]['imageContext']['languageHints']), 'Language hints should be omitted to allow mixed-language detection.');

    $symbols = [];
    foreach (preg_split('//u', 'Ƙarɓi') ?: [] as $character) {
        if ($character !== '') $symbols[] = ['text' => $character];
    }
    return ['status' => 200, 'body' => json_encode([
        'responses' => [[
            'fullTextAnnotation' => [
                'text' => "Ƙarɓi ɗalibi",
                'pages' => [[
                    'property' => ['detectedLanguages' => [['languageCode' => 'ha']]],
                    'blocks' => [[
                        'paragraphs' => [[
                            'words' => [[
                                'confidence' => 0.42,
                                'symbols' => $symbols,
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
};

try {
    $provider = new GoogleCloudVisionOcrProvider($tokens, 'pdfinfo', 'pdftoppm', $visionHttp);
    $result = $provider->recognize(new OcrRequest($imagePath, 'image/png', 'ha'));

    expectVision($result->text === "Ƙarɓi ɗalibi", 'OCR text was not extracted correctly.');
    expectVision($result->detectedLanguage === 'ha', 'Hausa language detection was not preserved.');
    expectVision(count($result->uncertainSpans) === 1, 'Low-confidence word was not marked uncertain.');
    expectVision($result->uncertainSpans[0]['confidence'] === 0.42, 'Word confidence was not preserved.');
    expectVision($visionCalls === 1 && $tokenCalls === 1, 'Expected one Vision call and one OAuth token call.');
    $tokens->accessToken();
    expectVision($tokenCalls === 1, 'OAuth token should be cached within the worker process.');

    $pdfPath = sys_get_temp_dir() . '/kavoryn-vision-pdf-' . bin2hex(random_bytes(5)) . '.pdf';
    $pdf = "%PDF-1.4\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
    ];
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 4\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    file_put_contents($pdfPath, $pdf);

    $heartbeatCount = 0;
    try {
        $pdfResult = $provider->recognize(new OcrRequest(
            $pdfPath,
            'application/pdf',
            'ha',
            function () use (&$heartbeatCount): void { $heartbeatCount++; },
        ));
        expectVision($pdfResult->text === "Ƙarɓi ɗalibi", 'PDF page OCR text was not returned.');
        expectVision($heartbeatCount >= 2, 'Long PDF processing must renew the worker lease between stages.');
        expectVision($visionCalls === 2, 'PDF page must be rendered and submitted to Vision.');
    } finally {
        @unlink($pdfPath);
    }
} finally {
    @unlink($credentialsPath);
    @unlink($imagePath);
}

echo "Google Cloud Vision OCR provider tests passed." . PHP_EOL;
