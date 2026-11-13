<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

interface ProviderStreamTransport
{
    /**
     * @param callable(array{type:'delta',text:string}|array{type:'completed'}):void $onEvent
     * @param null|callable():bool $isCancelled
     */
    public function streamRequest(
        ProviderStreamRequest $request,
        callable $onEvent,
        ?callable $isCancelled = null,
    ): void;
}
