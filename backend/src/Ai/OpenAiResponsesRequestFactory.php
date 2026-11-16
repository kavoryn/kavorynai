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

    /**
     * @param list<array{role:string,content:string}> $history Completed, owner-scoped prior turns only.
     */
    public function create(string $userInput, array $history = []): ProviderStreamRequest
    {
        if (trim($userInput) === '' || strlen($userInput) > 100000
            || !array_is_list($history) || count($history) > 30) {
            throw new \InvalidArgumentException('The chat input or history is invalid.');
        }

        $totalCharacters = 0;
        $input = [];
        foreach ($history as $item) {
            if (!is_array($item)
                || !in_array($item['role'] ?? null, ['user', 'assistant'], true)
                || !is_string($item['content'] ?? null)
                || trim($item['content']) === ''
                || preg_match('/\A.{1,24000}\z/us', $item['content']) !== 1) {
                throw new \InvalidArgumentException('The chat history is invalid.');
            }
            preg_match_all('/./us', $item['content'], $characters);
            $totalCharacters += count($characters[0]);
            if ($totalCharacters > 32000) {
                throw new \InvalidArgumentException('The chat history is too large.');
            }
            $input[] = ['role' => $item['role'], 'content' => $item['content']];
        }

        preg_match_all('/./us', $userInput, $currentCharacters);
        $totalCharacters += count($currentCharacters[0]);
        if ($totalCharacters > 44000) {
            throw new \InvalidArgumentException('The chat input and history are too large.');
        }
        $input[] = ['role' => 'user', 'content' => $userInput];

        $payload = [
            'model' => $this->model,
            'instructions' => $this->systemInstruction,
            'input' => $input,
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
