<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

use Kavoryn\Ocr\OcrResult;

interface DocumentExplainer
{
    /**
     * @return array{summary:string,detailed_explanation:string,actions:list<string>,key_facts:list<array{label:string,value:string,source_quote:string}>}
     */
    public function explain(OcrResult $ocr, string $language): array;
}
