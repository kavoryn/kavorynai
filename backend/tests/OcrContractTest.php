<?php

declare(strict_types=1);

use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';

final class OcrContractTest extends TestCase
{
    public function testRequestAcceptsSupportedLanguageAndMediaType(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kavoryn-ocr-');
        self::assertIsString($path);

        try {
            $request = new OcrRequest($path, 'image/png', 'ha');
            self::assertSame('ha', $request->language);
        } finally {
            @unlink($path);
        }
    }

    public function testRequestRejectsUnsupportedLanguage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kavoryn-ocr-');
        self::assertIsString($path);

        try {
            $this->expectException(InvalidArgumentException::class);
            new OcrRequest($path, 'image/png', 'ig');
        } finally {
            @unlink($path);
        }
    }

    public function testResultPreservesHausaAndArabicUnicode(): void
    {
        $text = "Ƙarɓi: ɗalibi ya sayi ƙwai da ɓawon lemu.\nيرجى قراءة هذه الوثيقة";
        $result = new OcrResult($text, [
            ['start' => 0, 'end' => 5, 'confidence' => 0.42, 'reason' => 'low_confidence'],
        ], 'ha');

        self::assertSame($text, $result->text);
        self::assertSame('ha', $result->detectedLanguage);
    }

    public function testResultRejectsInvalidUncertaintyOffsets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OcrResult('short', [
            ['start' => 0, 'end' => 99, 'confidence' => 0.2, 'reason' => 'low_confidence'],
        ]);
    }
}
