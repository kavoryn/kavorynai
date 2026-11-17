<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ChatProviderEnvironmentFactory
{
    private const STAGING_PREVIEW_BRANCH = 'integration/chat-stageb-secret-key-preview';

    /**
     * Resolve chat provider configuration without exposing the staging-only
     * compatibility variable to Production or other Preview branches.
     *
     * @param array<string, mixed> $environment
     * @return array<string, string>
     */
    public static function fromEnvironment(array $environment): array
    {
        $openAiApiKey = self::stringValue($environment['OPENAI_API_KEY'] ?? null);

        if (
            trim($openAiApiKey) === '' &&
            self::stringValue($environment['VERCEL_ENV'] ?? null) === 'preview' &&
            self::stringValue($environment['VERCEL_GIT_COMMIT_REF'] ?? null) === self::STAGING_PREVIEW_BRANCH
        ) {
            $openAiApiKey = self::stringValue($environment['OPENAI_API_KE'] ?? null);
        }

        return [
            'OPENAI_API_KEY' => $openAiApiKey,
            'OPENAI_CHAT_MODEL' => self::stringValue($environment['OPENAI_CHAT_MODEL'] ?? null),
            'GEMINI_API_KEY' => self::stringValue($environment['GEMINI_API_KEY'] ?? null),
            'GEMINI_CHAT_MODEL' => self::stringValue($environment['GEMINI_CHAT_MODEL'] ?? null),
        ];
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
