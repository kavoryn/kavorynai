<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

interface ProviderSseEventMapper
{
    /**
     * Convert a provider SSE event into a provider-neutral chat event.
     *
     * @param array{event:string,data:string,id:?string} $event
     * @return array{type:'delta',text:string}|array{type:'completed'}|null
     */
    public function map(array $event): ?array;
}
