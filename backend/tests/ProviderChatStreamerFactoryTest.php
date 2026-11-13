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

$config = [
    'OPENAI_API_KEY' => 'openai-test-key',
    'OPENAI_CHAT_MODEL' => 'gpt-test-model',
    'GEMINI_API_KEY' => 'gemini-test-key',
    'GEMINI_CHAT_MODEL' => 'gemini-test-model',
];

$streamer = ProviderChatStreamerFactory::fromEnvironment($config);
if (!$streamer instanceof \\Kavoryn\\Ai\\ProviderChatStreamer) {
    throw new RuntimeException('The server configuration factory must return a provider chat streamer.');
}

foreach (['OPENAI_API_KEY', 'OPENAI_CHAT_MODEL', 'GEMINI_API_KEY', 'GEMINI_CHAT_MODEL'] as $missing) {
    $incomplete = $config;
    unset($incomplete[$missing]);
    try {
        ProviderChatStreamerFactory::fromEnvironment($incomplete);
        throw new RuntimeException('Missing server configuration must fail closed.');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'AI chat server configuration is incomplete.') {
            throw $error;
        }
    }
}

$unsafe = $config;
$unsafe['OPENAI_API_KEY'] = "bad-key\\r\\nX-Injected: true";
try {
    ProviderChatStreamerFactory::fromEnvironment($unsafe);
    throw new RuntimeException('Unsafe provider credentials must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Provider chat streamer factory tests passed." . PHP_EOL;
