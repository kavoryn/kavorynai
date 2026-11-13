<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiDocumentExplainer.php';

use Kavoryn\\Ai\\OpenAiDocumentExplainer;
use Kavoryn\\Ai\\OpenAiUnavailableException;
use Kavoryn\\Ocr\\OcrResult;

function expectOpenAi(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$ocrText = "Invoice number INV-204. Total: NGN 15,000. Payment due 30 November 2026.";
$mock = function (string $url, array $headers, string $body) use ($ocrText): array {
    expectOpenAi($url === 'https://api.openai.com/v1/responses', 'Unexpected OpenAI endpoint.');
    expectOpenAi(($headers['Authorization'] ?? '') === 'Bearer test-server-key', 'Server API key missing.');
    $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    expectOpenAi(($request['store'] ?? null) === false, 'Document requests must not be stored by the Responses API.');
    expectOpenAi(($request['text']['format']['type'] ?? null) === 'json_schema', 'Strict JSON schema output is required.');
    expectOpenAi(str_contains($request['instructions'] ?? '', 'Hausa'), 'Requested language must be explicit.');
    expectOpenAi(str_contains($request['input'][0]['content'][0]['text'] ?? '', $ocrText), 'OCR text was not included.');
    $result = [
        'summary' => 'Wannan takarda invoice ce.',
        'detailed_explanation' => 'Jimillar kuɗi ita ce NGN 15,000.',
        'actions' => ['Tabbatar da ranar biya.'],
        'key_facts' => [[
            'label' => 'Jimillar kuɗi',
            'value' => 'NGN 15,000',
            'source_quote' => 'Total: NGN 15,000',
        ]],
    ];
    return ['status' => 200, 'body' => json_encode([
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
        ]],
    ], JSON_THROW_ON_ERROR)];
};

$explainer = new OpenAiDocumentExplainer('test-server-key', 'test-model', $mock);
$result = $explainer->explain(new OcrResult($ocrText, [], 'en'), 'ha');
expectOpenAi($result['key_facts'][0]['source_quote'] === 'Total: NGN 15,000', 'Supported source quote was not preserved.');

$badQuote = function (string $url, array $headers, string $body): array {
    return ['status' => 200, 'body' => json_encode([
        'status' => 'completed',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => json_encode([
                'summary' => 'x', 'detailed_explanation' => 'y', 'actions' => [],
                'key_facts' => [['label' => 'Amount', 'value' => 'NGN 99', 'source_quote' => 'NGN 99']],
            ])]],
        ]],
    ], JSON_THROW_ON_ERROR)];
};
try {
    (new OpenAiDocumentExplainer('test-server-key', 'test-model', $badQuote))
        ->explain(new OcrResult($ocrText), 'en');
    throw new RuntimeException('Unsupported source quote should be rejected.');
} catch (OpenAiUnavailableException) {
    // Expected: generated facts must be grounded in the source OCR text.
}

echo "OpenAI document explainer tests passed." . PHP_EOL;
