<?php

declare(strict_types=1);

namespace Kavoryn\\Ai;

use Kavoryn\\Ocr\\OcrResult;

interface DocumentQuestionAnswerer
{
    /**
     * Answers one question using only this document's OCR text.
     * Each source excerpt must be an exact substring of the OCR text.
     *
     * @return array{answer:string,source_excerpts:list<string>,insufficient_information:bool}
     */
    public function answer(OcrResult $ocr, string $question, string $language): array;
}
