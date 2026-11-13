<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/ProviderSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamException.php';
require_once dirname(__DIR__) . '/src/Ai/OpenAiSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/GeminiSseEventMapper.php';

use Kavoryn\Ai\GeminiSseEventMapper;
use Kavoryn\Ai\OpenAiSseEventMapper;
use Kavoryn\Ai\ProviderStreamException;

function mapperExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$openAi = new OpenAiSseEventMapper();
mapperExpect($openAi->map([
    'event' => 'response.output_text.delta',
    'data' => '{"type":"response.output_text.delta","delta":"Hello"}',
    'id' => null,
]) === ['type' => 'delta', 'text' => 'Hello'], 'OpenAI text deltas should map to provider-neutral deltas.');
mapperExpect($openAi->map([
    'event' => 'response.completed',
    'data' => '{"type":"response.completed"}',
    'id' => null,
]) === ['type' => 'completed'], 'OpenAI completion should map to completed.');
mapperExpect($openAi->map([
    'event' => 'response.created',
    'data' => '{"type":"response.created"}',
    'id' => null,
]) === null, 'OpenAI metadata events should be ignored.');
mapperExpect($openAi->map([
    'event' => 'message',
    'data' => '[DONE]',
    'id' => null,
]) === ['type' => 'completed'], 'OpenAI DONE sentinel should complete the stream.');

$gemini = new GeminiSseEventMapper();
mapperExpect($gemini->map([
    'event' => 'step.delta',
    'data' => '{"event_type":"step.delta","delta":{"type":"text","text":"Sannu"}}',
    'id' => null,
]) === ['type' => 'delta', 'text' => 'Sannu'], 'Gemini text deltas should map to provider-neutral deltas.');
mapperExpect($gemini->map([
    'event' => 'step.delta',
    'data' => '{"event_type":"step.delta","delta":{"type":"thought_summary","content":{"text":"private"}}}',
    'id' => null,
]) === null, 'Gemini non-output deltas must not be exposed as chat text.');
mapperExpect($gemini->map([
    'event' => 'interaction.completed',
    'data' => '{"event_type":"interaction.completed"}',
    'id' => null,
]) === ['type' => 'completed'], 'Gemini completion should map to completed.');
mapperExpect($gemini->map([
    'event' => 'interaction.created',
    'data' => '{"event_type":"interaction.created"}',
    'id' => null,
]) === null, 'Gemini metadata events should be ignored.');

foreach ([$openAi, $gemini] as $mapper) {
    try {
        $mapper->map(['event' => 'error', 'data' => '{"error":{"message":"secret detail"}}', 'id' => null]);
        throw new RuntimeException('Provider error events must fail safely.');
    } catch (ProviderStreamException $error) {
        mapperExpect(!str_contains($error->getMessage(), 'secret detail'), 'Provider error details must not leak.');
    }

    try {
        $mapper->map(['event' => 'step.delta', 'data' => '{invalid', 'id' => null]);
        throw new RuntimeException('Malformed provider events must fail safely.');
    } catch (ProviderStreamException) {
        // Expected malformed event handling.
    }
}

echo "Provider SSE event mapper tests passed." . PHP_EOL;
