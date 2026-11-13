<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ai/DocumentExplainer.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiDocumentExplainer.php';

use Kavoryn\Ai\GeminiDocumentExplainer;
use Kavoryn\Ai\GeminiUnavailableException;
use Kavoryn\Ocr\OcrResult;

function geminiExpect(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$text = "Letter reference KAV-2026. Amount: NGN 42,500. Deadline: 12 December 2026.";
$mock = function (string $url, array $headers, string $body) use ($text): array {
    geminiExpect($url === 'https://generativelanguage.googleapis.com/v1beta/interactions', 'Wrong Gemini endpoint.');
    geminiExpect(($headers['x-goog-api-key'] ?? '') === 'test-gemini-key', 'Gemini key header missing.');
    $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    geminiExpect(($request['store'] ?? null) === false, 'Gemini interaction storage must be disabled.');
    geminiExpect(($request['response_format']['mime_type'] ?? null) === 'application/json', 'JSON output format is required.');
    geminiExpect(str_contains($request['system_instruction'] ?? '', 'Hausa'), 'Requested language must be explicit.');
    geminiExpect(str_contains($request['input'] ?? '', $text), 'OCR source text is missing.');
    $result = [
        'summary' => 'Wannan wasiƙa ce.',
        'detailed_explanation' => 'Adadin da aka rubuta shi ne NGN 42,500.',
        'actions' => ['Duba ranar ƙarshe.'],
        'key_facts' => [[
            'label' => 'Adadi',
            'value' => 'NGN 42,500',
            'source_quote' => 'Amount: NGN 42,500',
        ]],
    ];
    return ['status' => 200, 'body' => json_encode([
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
        ]],
    ], JSON_THROW_ON_ERROR)];
};

$result = (new GeminiDocumentExplainer('test-gemini-key', 'gemini-3.6-flash', $mock))
    ->explain(new OcrResult($text, [], 'en'), 'ha');
geminiExpect($result['key_facts'][0]['source_quote'] === 'Amount: NGN 42,500', 'Exact source quote was not retained.');

$badQuote = function (string $url, array $headers, string $body): array {
    return ['status' => 200, 'body' => json_encode([
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => json_encode([
                'summary' => 'x', 'detailed_explanation' => 'y', 'actions' => [],
                'key_facts' => [['label' => 'Amount', 'value' => 'NGN 9', 'source_quote' => 'NGN 9']],
            ])]],
        ]],
    ], JSON_THROW_ON_ERROR)];
};
try {
    (new GeminiDocumentExplainer('test-gemini-key', 'gemini-3.6-flash', $badQuote))
        ->explain(new OcrResult($text), 'en');
    throw new RuntimeException('Unsupported source quote should be rejected.');
} catch (GeminiUnavailableException) {
    // Expected: facts must be grounded in the OCR source.
}

echo "Gemini document explainer tests passed." . PHP_EOL;
