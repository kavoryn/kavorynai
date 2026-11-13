<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

final class ProviderChatStreamer
{
    public function __construct(
        private readonly OpenAiResponsesRequestFactory $openAi,
        private readonly GeminiInteractionsRequestFactory $gemini,
        private readonly ProviderStreamTransport $transport,
    ) {
    }

    /**
     * Provider choice is explicit. This service never silently falls back.
     *
     * @param callable(array{type:'delta',text:string}|array{type:'completed'}):void $onEvent
     * @param null|callable():bool $isCancelled
     */
    public function stream(
        string $provider,
        string $userInput,
        callable $onEvent,
        ?callable $isCancelled = null,
    ): void {
        $request = match ($provider) {
            'openai' => $this->openAi->create($userInput),
            'gemini' => $this->gemini->create($userInput),
            default => throw new \InvalidArgumentException('The requested AI provider is not supported.'),
        };

        $this->transport->streamRequest($request, $onEvent, $isCancelled);
    }
}
