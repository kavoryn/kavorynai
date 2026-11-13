<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

interface OcrResultSink
{
    /**
     * Store the OCR result using the product's approved retention and encryption policy.
     * Implementations must not log or expose document text.
     */
    public function store(OcrJob $job, OcrResult $result): void;
}
