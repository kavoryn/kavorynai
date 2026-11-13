<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/SseEventDecoder.php';

use Kavoryn\Ai\SseEventDecoder;

function sseExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$decoder = new SseEventDecoder();
sseExpect($decoder->push(": keepalive\r") === [], 'A trailing CR must wait for the next chunk.');
$events = $decoder->push("\nevent: delta\r\ndata: {\"text\":\"A\"}\r\ndata: line2\r\nid: 1\r\n\r\n");
sseExpect(count($events) === 1, 'One complete event should be dispatched.');
sseExpect($events[0] === [
    'event' => 'delta',
    'data' => "{\"text\":\"A\"}\nline2",
    'id' => '1',
], 'Named event, multi-line data and ID should be decoded.');

$next = $decoder->push("data: second\n\n");
sseExpect($next === [[
    'event' => 'message',
    'data' => 'second',
    'id' => '1',
]], 'Default event type and last event ID should be retained correctly.');

$unfinished = new SseEventDecoder();
sseExpect($unfinished->push("event: final\ndata: last chunk") === [], 'An incomplete event must not dispatch early.');
sseExpect($unfinished->finish() === [[
    'event' => 'final',
    'data' => 'last chunk',
    'id' => null,
]], 'finish() should dispatch a final unterminated event.');

$invalidId = new SseEventDecoder();
$invalidEvents = $invalidId->push("id: bad\0id\ndata: safe\n\n");
sseExpect(($invalidEvents[0]['id'] ?? null) === null, 'An ID containing NUL must be ignored.');
sseExpect($invalidId->finish() === [], 'A dispatched event should not be repeated by finish().');

try {
    (new SseEventDecoder(8))->push("data: too-long\n");
    throw new RuntimeException('Oversized events must be rejected.');
} catch (LengthException) {
    // Expected bounded-buffer behavior.
}

try {
    new SseEventDecoder(0);
    throw new RuntimeException('Invalid event limits must be rejected.');
} catch (InvalidArgumentException) {
    // Expected configuration validation.
}

echo "SSE event decoder tests passed." . PHP_EOL;
