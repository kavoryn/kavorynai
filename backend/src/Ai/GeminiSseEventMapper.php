<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class GeminiSseEventMapper implements ProviderSseEventMapper
{
    public function map(array $event): ?array
    {
        if ($event['data'] === '[DONE]') {
            return ['type' => 'completed'];
        }

        try {
            $payload = json_decode($event['data'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderStreamException('The AI provider returned an invalid stream event.');
        }

        if (!is_array($payload)) {
            throw new ProviderStreamException('The AI provider returned an invalid stream event.');
        }

        $type = $event['event'] !== '' ? $event['event'] : ($payload['event_type'] ?? '');
        if (!is_string($type)) {
            throw new ProviderStreamException('The AI provider returned an invalid stream event.');
        }

        if ($type === 'error') {
            throw new ProviderStreamException('The AI provider could not complete the response.');
        }

        if ($type === 'interaction.completed') {
            return ['type' => 'completed'];
        }

        if ($type !== 'step.delta') {
            return null;
        }

        $delta = $payload['delta'] ?? null;
        if (!is_array($delta) || ($delta['type'] ?? null) !== 'text') {
            return null;
        }
        if (!is_string($delta['text'] ?? null)) {
            throw new ProviderStreamException('The AI provider returned an invalid text event.');
        }

        return ['type' => 'delta', 'text' => $delta['text']];
    }
}
