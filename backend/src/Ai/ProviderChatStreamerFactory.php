<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ProviderChatStreamerFactory
{
    private const SYSTEM_INSTRUCTION = 'You are Kavoryn AI, a helpful, accurate, privacy-conscious multilingual assistant. Be clear about uncertainty. Never claim to have performed an action unless it was actually completed.';

    /**
     * Build provider adapters from server-side configuration only.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment, string $provider): ProviderChatStreamer
    {
        if (!in_array($provider, ['openai', 'gemini'], true)) {
            throw new \InvalidArgumentException('The requested AI provider is not supported.');
        }

        $transport = new CurlSseStreamTransport([
            'api.openai.com',
            'generativelanguage.googleapis.com',
        ]);

        $openAi = null;
        $gemini = null;

        if ($provider === 'openai') {
            $openAi = new OpenAiResponsesRequestFactory(
                self::required($environment, 'OPENAI_API_KEY'),
                self::required($environment, 'OPENAI_CHAT_MODEL'),
                self::SYSTEM_INSTRUCTION,
            );
        } else {
            $gemini = new GeminiInteractionsRequestFactory(
                self::required($environment, 'GEMINI_API_KEY'),
                self::required($environment, 'GEMINI_CHAT_MODEL'),
                self::SYSTEM_INSTRUCTION,
            );
        }

        return new ProviderChatStreamer($openAi, $gemini, $transport);
    }

    private static function required(array $environment, string $name): string
    {
        $value = $environment[$name] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('AI chat server configuration is incomplete.');
        }

        return $value;
    }
}
