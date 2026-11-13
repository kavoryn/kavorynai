<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final readonly class OcrResult
{
    /**
     * @param list<array{start:int,end:int,confidence:float,reason:string}> $uncertainSpans
     */
    public function __construct(
        public string $text,
        public array $uncertainSpans = [],
        public ?string $detectedLanguage = null,
    ) {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('OCR text must be valid UTF-8.');
        }

        if ($detectedLanguage !== null &&
            !in_array($detectedLanguage, ['en', 'ha', 'ar', 'fr', 'und'], true)) {
            throw new \InvalidArgumentException('The detected language code is invalid.');
        }

        preg_match_all('/./us', $text, $characters);
        $characterCount = count($characters[0]);

        foreach ($uncertainSpans as $span) {
            if (!is_array($span) ||
                !is_int($span['start'] ?? null) ||
                !is_int($span['end'] ?? null) ||
                (!is_float($span['confidence'] ?? null) && !is_int($span['confidence'] ?? null)) ||
                !is_string($span['reason'] ?? null) ||
                $span['start'] < 0 ||
                $span['end'] <= $span['start'] ||
                $span['end'] > $characterCount ||
                $span['confidence'] < 0.0 ||
                $span['confidence'] > 1.0 ||
                $span['reason'] === '') {
                throw new \InvalidArgumentException('An OCR uncertainty span is invalid.');
            }
        }
    }
}
