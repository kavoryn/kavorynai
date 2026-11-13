<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/ProviderSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamException.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamRequest.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiResponsesRequestFactory.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiInteractionsRequestFactory.php';

use Kavoryn\Ai\GeminiInteractionsRequestFactory;
use Kavoryn\Ai\OpenAiResponsesRequestFactory;

function requestExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$openAi = (new OpenAiResponsesRequestFactory('test-openai-key', 'gpt-6-astra', 'Be concise.'))->create('Hello, Kavoryn');
$openAiBody = json_decode($openAi->body, true, 32, JSON_THROW_ON_ERROR);
requestExpect($openAi->url === 'https://api.openai.com/v1/responses', 'OpenAI endpoint must be fixed HTTPS.');
requestExpect($openAiBody['stream'] === true && $openAiBody['store'] === false, 'OpenAI streaming must be enabled and provider storage disabled.');
requestExpect($openAiBody['input'] === 'Hello, Kavoryn', 'OpenAI input must be preserved.');
requestExpect(in_array('Authorization: Bearer test-openai-key', $openAi->headers, true), 'OpenAI bearer key must be server-side header.');
requestExpect($openAi->mapper instanceof \Kavoryn\Ai\OpenAiSseEventMapper, 'OpenAI mapper must be selected explicitly.');

$gemini = (new GeminiInteractionsRequestFactory('test-gemini-key', 'gemini-3.8-flash', 'Be concise.'))->create('Sannu Kavoryn');
$geminiBody = json_decode($gemini->body, true, 32, JSON_THROW_ON_ERROR);
requestExpect($gemini->url === 'https://generativelanguage.googleapis.com/v1beta/interactions', 'Gemini endpoint must be fixed HTTPS.');
requestExpect($geminiBody['stream'] === true && $geminiBody['store'] === false, 'Gemini streaming must be enabled with provider storage disabled.');
requestExpect($geminiBody['input'] === 'Sannu Kavoryn', 'Gemini input must be preserved.');
requestExpect(in_array('x-goog-api-key: test-gemini-key', $gemini->headers, true), 'Gemini key must be sent in its server-side header.');
requestExpect($gemini->mapper instanceof \Kavoryn\Ai\GeminiSseEventMapper, 'Gemini mapper must be selected explicitly.');

foreach ([
    static fn () => new OpenAiResponsesRequestFactory("bad\nheader", 'gpt-6-astra', ''),
    static fn () => new GeminiInteractionsRequestFactory("bad\nheader", 'gemini-3.8-flash', ''),
    static fn () => new OpenAiResponsesRequestFactory('key', '../invalid', ''),
    static fn () => new GeminiInteractionsRequestFactory('key', '../invalid', ''),
] as $invalidFactory) {
    try {
        $invalidFactory();
        throw new RuntimeException('Invalid provider configuration must be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "Provider stream request factory tests passed." . PHP_EOL;
