<?php

declare(strict_types=1);

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

use Kavoryn\Ai\ProviderChatStreamerFactory;

$openAiOnly = [
    'OPENAI_API_KEY' => 'openai-test-key',
    'OPENAI_CHAT_MODEL' => 'gpt-test-model',
];
$geminiOnly = [
    'GEMINI_API_KEY' => 'gemini-test-key',
    'GEMINI_CHAT_MODEL' => 'gemini-test-model',
];

foreach ([['openai', $openAiOnly], ['gemini', $geminiOnly]] as [$provider, $config]) {
    $streamer = ProviderChatStreamerFactory::fromEnvironment($config, $provider);
    if (!$streamer instanceof \Kavoryn\Ai\ProviderChatStreamer) {
        throw new RuntimeException('The selected provider configuration must create a streamer.');
    }
}

foreach ([
    ['openai', ['OPENAI_API_KEY' => 'openai-test-key']],
    ['gemini', ['GEMINI_CHAT_MODEL' => 'gemini-test-model']],
] as [$provider, $incomplete]) {
    try {
        ProviderChatStreamerFactory::fromEnvironment($incomplete, $provider);
        throw new RuntimeException('Missing selected-provider configuration must fail closed.');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'AI chat server configuration is incomplete.') {
            throw $error;
        }
    }
}

try {
    ProviderChatStreamerFactory::fromEnvironment([], 'unknown');
    throw new RuntimeException('An unsupported provider must be rejected.');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'The requested AI provider is not supported.') {
        throw $error;
    }
}

$unsafe = $openAiOnly;
$unsafe['OPENAI_API_KEY'] = "bad-key\r\nX-Injected: true";
try {
    ProviderChatStreamerFactory::fromEnvironment($unsafe, 'openai');
    throw new RuntimeException('Unsafe provider credentials must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Provider chat streamer factory tests passed." . PHP_EOL;
