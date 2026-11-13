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
    public static function fromEnvironment(array $environment): ProviderChatStreamer
    {
        $openAiKey = self::required($environment, 'OPENAI_API_KEY');
        $openAiModel = self::required($environment, 'OPENAI_CHAT_MODEL');
        $geminiKey = self::required($environment, 'GEMINI_API_KEY');
        $geminiModel = self::required($environment, 'GEMINI_CHAT_MODEL');

        $transport = new CurlSseStreamTransport([
            'api.openai.com',
            'generativelanguage.googleapis.com',
        ]);

        return new ProviderChatStreamer(
            new OpenAiResponsesRequestFactory(
                $openAiKey,
                $openAiModel,
                self::SYSTEM_INSTRUCTION,
            ),
            new GeminiInteractionsRequestFactory(
                $geminiKey,
                $geminiModel,
                self::SYSTEM_INSTRUCTION,
            ),
            $transport,
        );
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
