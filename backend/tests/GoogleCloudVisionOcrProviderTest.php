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

    // Mixed Hausa/Arabic fixture: verify UTF-8 text is preserved and uncertainty
    // offsets count Unicode code points rather than UTF-8 bytes.
    $mixedText = "Hausa ɓ ɗ ƙ ƴ — مستند رسمي";
    $mixedWords = [
        ['text' => 'ɓ', 'confidence' => 0.99],
        ['text' => 'ɗ', 'confidence' => 0.99],
        ['text' => 'ƙ', 'confidence' => 0.55],
        ['text' => 'ƴ', 'confidence' => 0.99],
        ['text' => 'مستند', 'confidence' => 0.48],
        ['text' => 'رسمي', 'confidence' => 0.96],
    ];
    $mixedImagePath = sys_get_temp_dir() . '/kavory-vision-mixed-' . bin2hex(random_bytes(5)) . '.png';
    file_put_contents($mixedImagePath, 'mixed-language-fixture');
    $mixedVisionHttp = function (string $url, array $headers, string $body) use ($mixedText, $mixedWords): array {
        $symbolsFor = static function (string $word): array {
            $symbols = [];
            foreach (preg_split('//u', $word) ?: [] as $character) {
                if ($character !== '') $symbols[] = ['text' => $character];
            }
            return $symbols;
        };
        $words = [];
        foreach ($mixedWords as $word) {
            $words[] = [
                'confidence' => $word['confidence'],
                'symbols' => $symbolsFor($word['text']),
            ];
        }
        return ['status' => 200, 'body' => json_encode([
            'responses' => [[
                'fullTextAnnotation' => [
                    'text' => $mixedText,
                    'pages' => [[
                        'property' => ['detectedLanguages' => [
                            ['languageCode' => 'ha'],
                            ['languageCode' => 'ar'],
                        ]],
                        'blocks' => [[
                            'paragraphs' => [[
                                'words' => $words,
                            ]],
                        ]],
                    ]],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    };

    try {
        $mixedProvider = new GoogleCloudVisionOcrProvider($tokens, 'pdfinfo', 'pdftoppm', $mixedVisionHttp);
        $mixedResult = $mixedProvider->recognize(new OcrRequest($mixedImagePath, 'image/png', 'ha'));
        expectVision($mixedResult->text === $mixedText, 'Mixed Hausa/Arabic OCR text must be returned without normalization or character loss.');
        expectVision($mixedResult->detectedLanguage === 'und', 'A page with both Hausa and Arabic detections must not be labelled as a single language.');
        expectVision(count($mixedResult->uncertainSpans) === 2, 'Low-confidence Hausa and Arabic words must both be marked uncertain.');

        $codePoints = preg_split('//u', $mixedResult->text, -1, PREG_SPLIT_NO_EMPTY);
        expectVision(is_array($codePoints), 'Mixed-language OCR output must remain valid UTF-8.');
        $uncertainWords = [];
        foreach ($mixedResult->uncertainSpans as $span) {
            $uncertainWords[] = implode('', array_slice($codePoints, $span['start'], $span['end'] - $span['start']));
        }
        expectVision($uncertainWords === ['ƙ', 'مستند'], 'Uncertainty offsets must select the exact Hausa and Arabic words using Unicode character positions.');
        expectVision($mixedResult->uncertainSpans[0]['confidence'] === 0.55, 'Hausa confidence must remain unchanged.');
        expectVision($mixedResult->uncertainSpans[1]['confidence'] === 0.48, 'Arabic confidence must remain unchanged.');
    } finally {
        @unlink($mixedImagePath);
    }

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
