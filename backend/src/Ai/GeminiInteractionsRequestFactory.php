<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class GeminiInteractionsRequestFactory
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $systemInstruction,
        private readonly int $maxOutputTokens = 4096,
    ) {
        if (trim($apiKey) === '' || str_contains($apiKey, "\r") || str_contains($apiKey, "\n")) {
            throw new \InvalidArgumentException('The Gemini API credential is not configured safely.');
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $model) !== 1
            || $maxOutputTokens < 1 || $maxOutputTokens > 32768) {
            throw new \InvalidArgumentException('The Gemini request configuration is invalid.');
        }
    }

    public function create(string $userInput): ProviderStreamRequest
    {
        if (trim($userInput) === '' || strlen($userInput) > 100000) {
            throw new \InvalidArgumentException('The chat input is empty or too large.');
        }

        $payload = [
            'model' => $this->model,
            'input' => $userInput,
            'system_instruction' => $this->systemInstruction,
            'stream' => true,
            'store' => false,
            'generation_config' => [
                'max_output_tokens' => $this->maxOutputTokens,
            ],
        ];

        return new ProviderStreamRequest(
            'https://generativelanguage.googleapis.com/v1beta/interactions',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            [
                'x-goog-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            new GeminiSseEventMapper(),
        );
    }
}
