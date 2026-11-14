<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/ProviderSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamException.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamRequest.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamTransport.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiResponsesRequestFactory.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiInteractionsRequestFactory.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderChatStreamer.php';

use Kavoryn\Ai\GeminiInteractionsRequestFactory;
use Kavoryn\Ai\OpenAiResponsesRequestFactory;
use Kavoryn\Ai\ProviderChatStreamer;
use Kavoryn\Ai\ProviderStreamException;
use Kavoryn\Ai\ProviderStreamRequest;
use Kavoryn\Ai\ProviderStreamTransport;

$transport = new class implements ProviderStreamTransport {
    public ?ProviderStreamRequest $lastRequest = null;
    public bool $sawCancellation = false;
    public bool $failNext = false;

    public function streamRequest(ProviderStreamRequest $request, callable $onEvent, ?callable $isCancelled = null): void {
        $this->lastRequest = $request;
        if ($this->failNext) {
            $this->failNext = false;
            throw new ProviderStreamException('The AI provider stream was interrupted.');
        }
        if ($isCancelled !== null && $isCancelled()) {
            $this->sawCancellation = true;
            throw new ProviderStreamException('The chat response was cancelled.');
        }
        $onEvent(['type' => 'delta', 'text' => 'Hello']);
        $onEvent(['type' => 'completed']);
    }
};
$streamer = new ProviderChatStreamer(
    new OpenAiResponsesRequestFactory('openai-test-key', 'gpt-6-astra', 'Be helpful.'),
    new GeminiInteractionsRequestFactory('gemini-test-key', 'gemini-3.8-flash', 'Be helpful.'),
    $transport,
);
$events = [];
$streamer->stream('openai', 'Hello', static function (array $event) use (&$events): void { $events[] = $event; });
if (!str_starts_with($transport->lastRequest?->url ?? '', 'https://api.openai.com/')) throw new RuntimeException('OpenAI selection failed.');
if (($events[0]['text'] ?? null) !== 'Hello' || ($events[1]['type'] ?? null) !== 'completed') throw new RuntimeException('Stream events were not forwarded.');

$streamer->stream('gemini', 'Sannu', static function (array $event): void {});
if (!str_starts_with($transport->lastRequest?->url ?? '', 'https://generativelanguage.googleapis.com/')) throw new RuntimeException('Gemini selection failed.');

$cancelled = false;
try {
    $streamer->stream('openai', 'Cancel me', static function (array $event): void {}, static function () use (&$cancelled): bool {
        $cancelled = true;
        return true;
    });
    throw new RuntimeException('A cancelled stream should stop.');
} catch (ProviderStreamException $error) {
    if ($error->getMessage() !== 'The chat response was cancelled.') throw $error;
}
if (!$cancelled || !$transport->sawCancellation) throw new RuntimeException('Cancellation callback was not passed through to the transport.');

$transport->failNext = true;
try {
    $streamer->stream('openai', 'Fail safely', static function (array $event): void {});
    throw new RuntimeException('Transport failures should propagate.');
} catch (ProviderStreamException $error) {
    if ($error->getMessage() !== 'The AI provider stream was interrupted.') throw $error;
}

$before = $transport->lastRequest;
try {
    $streamer->stream('unknown', 'Hello', static function (array $event): void {});
    throw new RuntimeException('Unknown provider accepted.');
} catch (InvalidArgumentException $error) {
    if ($error->getMessage() !== 'The requested AI provider is not supported.') throw $error;
}
if ($transport->lastRequest !== $before) throw new RuntimeException('Unknown provider triggered a request.');

echo "Provider chat streamer tests passed." . PHP_EOL;
