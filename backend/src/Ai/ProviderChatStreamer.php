<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ProviderChatStreamer
{
    public function __construct(
        private readonly ?OpenAiResponsesRequestFactory $openAi,
        private readonly ?GeminiInteractionsRequestFactory $gemini,
        private readonly ProviderStreamTransport $transport,
    ) {
    }

    /**
     * Provider choice is explicit. This service never silently falls back.
     *
     * @param callable(array{type:'delta',text:string}|array{type:'completed'}):void $onEvent
     * @param null|callable():bool $isCancelled
     * @param list<array{role:string,content:string}> $history
     */
    public function stream(
        string $provider,
        string $userInput,
        callable $onEvent,
        ?callable $isCancelled = null,
        array $history = [],
    ): void {
        $request = match ($provider) {
            'openai' => $this->openAi?->create($userInput, $history)
                ?? throw new \InvalidArgumentException('The selected AI provider is not configured.'),
            'gemini' => $this->gemini?->create($userInput)
                ?? throw new \InvalidArgumentException('The selected AI provider is not configured.'),
            default => throw new \InvalidArgumentException('The requested AI provider is not supported.'),
        };

        if ($provider === 'gemini' && $history !== []) {
            throw new \InvalidArgumentException('Gemini multi-turn context is not enabled until its retention-safe step history is implemented.');
        }

        $this->transport->streamRequest($request, $onEvent, $isCancelled);
    }
}
