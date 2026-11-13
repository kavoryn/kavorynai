<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class OpenAiResponsesRequestFactory
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $systemInstruction,
        private readonly int $maxOutputTokens = 4096,
    ) {
        self::assertSecret($apiKey);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $model) !== 1
            || $maxOutputTokens < 1 || $maxOutputTokens > 32768) {
            throw new \InvalidArgumentException('The OpenAI request configuration is invalid.');
        }
    }

    public function create(string $userInput): ProviderStreamRequest
    {
        if (trim($userInput) === '' || strlen($userInput) > 100000) {
            throw new \InvalidArgumentException('The chat input is empty or too large.');
        }

        $payload = [
            'model' => $this->model,
            'instructions' => $this->systemInstruction,
            'input' => $userInput,
            'stream' => true,
            'store' => false,
            'max_output_tokens' => $this->maxOutputTokens,
        ];

        return new ProviderStreamRequest(
            'https://api.openai.com/v1/responses',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            new OpenAiSseEventMapper(),
        );
    }

    private static function assertSecret(string $secret): void
    {
        if (trim($secret) === '' || str_contains($secret, "\r") || str_contains($secret, "\n")) {
            throw new \InvalidArgumentException('The OpenAI API credential is not configured safely.');
        }
    }
}
