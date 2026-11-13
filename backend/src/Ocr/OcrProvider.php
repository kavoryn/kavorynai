<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

interface OcrProvider
{
    /**
     * Extract text from a validated private file.
     *
     * Implementations must not log file contents, persist source files, or silently
     * replace uncertain text with guesses. Provider credentials belong server-side.
     */
    public function recognize(OcrRequest $request): OcrResult;
}
