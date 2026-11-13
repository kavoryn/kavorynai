<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class OpenAiSseEventMapper implements ProviderSseEventMapper
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

        $type = $event['event'] !== '' ? $event['event'] : ($payload['type'] ?? '');
        if (!is_string($type)) {
            throw new ProviderStreamException('The AI provider returned an invalid stream event.');
        }

        if ($type === 'error' || $type === 'response.failed') {
            throw new ProviderStreamException('The AI provider could not complete the response.');
        }

        if ($type === 'response.completed') {
            return ['type' => 'completed'];
        }

        if ($type === 'response.output_text.delta') {
            if (!is_string($payload['delta'] ?? null)) {
                throw new ProviderStreamException('The AI provider returned an invalid text event.');
            }
            return ['type' => 'delta', 'text' => $payload['delta']];
        }

        return null;
    }
}
