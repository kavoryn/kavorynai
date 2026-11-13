<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

/**
 * Incremental decoder for server-sent events.
 *
 * It deliberately exposes only protocol fields; provider-specific JSON parsing
 * and event allow-lists belong in the provider adapters.
 */
final class SseEventDecoder
{
    private string $buffer = '';
    /** @var list<string> */
    private array $dataLines = [];
    private string $eventType = '';
    private ?string $lastEventId = null;
    private int $eventBytes = 0;

    public function __construct(private readonly int $maxEventBytes = 65536)
    {
        if ($maxEventBytes < 1 || $maxEventBytes > 1048576) {
            throw new \InvalidArgumentException('The SSE event size limit is invalid.');
        }
    }

    /**
     * @return list<array{event:string,data:string,id:?string}>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (true) {
            $lineLength = strcspn($this->buffer, "\r\n");
            if ($lineLength === strlen($this->buffer)) {
                break;
            }

            $delimiter = $this->buffer[$lineLength];
            // A CR at the end of a chunk may be the first half of CRLF.
            if ($delimiter === "\r" && $lineLength === strlen($this->buffer) - 1) {
                break;
            }

            $line = substr($this->buffer, 0, $lineLength);
            $consumed = $lineLength + 1;
            if ($delimiter === "\r" && ($this->buffer[$consumed] ?? null) === "\n") {
                $consumed++;
            }
            $this->buffer = substr($this->buffer, $consumed);

            $event = $this->consumeLine($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        if (strlen($this->buffer) > $this->maxEventBytes) {
            throw new \LengthException('The SSE line exceeds the configured size limit.');
        }

        return $events;
    }

    /**
     * Process a final unterminated line and dispatch any pending event.
     *
     * @return list<array{event:string,data:string,id:?string}>
     */
    public function finish(): array
    {
        $events = [];
        if ($this->buffer !== '') {
            $event = $this->consumeLine($this->buffer);
            $this->buffer = '';
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $event = $this->consumeLine('');
        if ($event !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @return array{event:string,data:string,id:?string}|null
     */
    private function consumeLine(string $line): ?array
    {
        if ($line === '') {
            if ($this->dataLines === []) {
                $this->eventType = '';
                $this->eventBytes = 0;
                return null;
            }

            $event = [
                'event' => $this->eventType !== '' ? $this->eventType : 'message',
                'data' => implode("\n", $this->dataLines),
                'id' => $this->lastEventId,
            ];
            $this->dataLines = [];
            $this->eventType = '';
            $this->eventBytes = 0;
            return $event;
        }

        if (str_starts_with($line, ':')) {
            return null;
        }

        $this->eventBytes += strlen($line) + 1;
        if ($this->eventBytes > $this->maxEventBytes) {
            throw new \LengthException('The SSE event exceeds the configured size limit.');
        }

        $separator = strpos($line, ':');
        if ($separator === false) {
            $field = $line;
            $value = '';
        } else {
            $field = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
        }

        switch ($field) {
            case 'data':
                $this->dataLines[] = $value;
                break;
            case 'event':
                $this->eventType = $value;
                break;
            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->lastEventId = $value;
                }
                break;
            case 'retry':
                // Reconnection timing is controlled by Kavoryn, not the provider.
                break;
        }

        return null;
    }
}
