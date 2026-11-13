<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class DocumentQuestionAnswererFactory
{
    public static function create(string $provider,array $environment): DocumentQuestionAnswerer
    {
        return match($provider) {
            'openai'=>new OpenAiDocumentQuestionAnswerer(
                (string)($environment['OPENAI_API_KEY']??''),
                (string)($environment['OPENAI_MODEL']??'gpt-5-mini'),
            ),
            'gemini'=>new GeminiDocumentQuestionAnswerer(
                (string)($environment['GEMINI_API_KEY']??''),
                (string)($environment['GEMINI_MODEL']??'gemini-3.6-flash'),
            ),
            default=>throw new \InvalidArgumentException('The requested AI provider is not supported.'),
        };
    }
}
