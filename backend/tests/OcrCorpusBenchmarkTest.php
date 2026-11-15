<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/ocr_corpus_benchmark.php';

function expectBenchmark(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$path = sys_get_temp_dir() . '/kavoryn-ocr-corpus-' . bin2hex(random_bytes(6)) . '.jsonl';
$rows = [
    ['id'=>'synthetic-ha-1','language'=>'ha','reference'=>'ɓ ɗ ƙ ƴ','hypothesis'=>'ɓ ɗ ƙ ƴ'],
    ['id'=>'synthetic-ha-2','language'=>'ha','reference'=>'Ƙarɓi ɗalibi','hypothesis'=>'Ƙarɓi ɗalibi'],
    ['id'=>'synthetic-ar-1','language'=>'ar','reference'=>'مستند رسمي','hypothesis'=>'مستند رسمى'],
    ['id'=>'synthetic-en-1','language'=>'en','reference'=>'hello world','hypothesis'=>'hello word'],
    ['id'=>'synthetic-en-insert-1','language'=>'en','reference'=>'red fox','hypothesis'=>'very red fox'],
    ['id'=>'synthetic-en-delete-1','language'=>'en','reference'=>'a bright day','hypothesis'=>'bright day'],
    ['id'=>'synthetic-mixed-1','language'=>'und','reference'=>'Kavoryn فاتورة','hypothesis'=>'Kavoryn فاتورة'],
    ['id'=>'synthetic-fr-1','language'=>'fr','reference'=>'économie publique','hypothesis'=>'economie publique'],
];
$lines = array_map(
    static fn(array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    $rows
);
file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);

try {
    $report = evaluateOcrCorpus($path);
    expectBenchmark(($report['ha']['samples'] ?? null) === 2, 'Hausa samples must be grouped by language.');
    expectBenchmark(($report['ha']['cer'] ?? null) === 0.0, 'Exact Hausa special-character fixtures must have zero CER.');
    expectBenchmark(($report['ha']['wer'] ?? null) === 0.0, 'Exact Hausa fixtures must have zero WER.');
    expectBenchmark(($report['ar']['samples'] ?? null) === 1, 'Arabic samples must be grouped by language.');
    expectBenchmark(($report['ar']['character_errors'] ?? null) === 1, 'Arabic character substitutions must count as an error.');
    expectBenchmark(($report['en']['samples'] ?? null) === 3, 'English samples must include substitution, insertion, and deletion fixtures.');
    expectBenchmark(($report['en']['word_errors'] ?? null) === 3, 'English substitutions, insertions, and deletions must all count as word errors.');
    expectBenchmark(($report['en']['reference_words'] ?? null) === 7, 'English reference words must be micro-aggregated across samples.');
    expectBenchmark(($report['en']['wer'] ?? null) === 0.428571, 'English WER must be micro-averaged as total edits divided by total reference words.');
    expectBenchmark(ocrBenchmarkEditDistance(['one', 'two'], ['zero', 'one', 'two']) === 1, 'Word insertion must count as one edit.');
    expectBenchmark(ocrBenchmarkEditDistance(['one', 'two'], ['two']) === 1, 'Word deletion must count as one edit.');
    expectBenchmark(ocrBenchmarkEditDistance(['a', 'b'], ['x', 'a', 'b']) === 1, 'Character insertion must count as one edit.');
    expectBenchmark(ocrBenchmarkEditDistance(['a', 'b'], ['b']) === 1, 'Character deletion must count as one edit.');
    expectBenchmark(ocrBenchmarkEditDistance(['one'], []) === 1, 'A complete word deletion must count as one edit.');
    expectBenchmark(
        ocrBenchmarkWords("alpha\u{00A0}beta\u{2003}gamma") === ['alpha', 'beta', 'gamma'],
        'Unicode no-break and em spaces must separate WER tokens.'
    );
    expectBenchmark(
        ocrBenchmarkWords("\u{00A0}\u{2003}") === [],
        'Unicode-whitespace-only text must produce no WER tokens.'
    );
    $invalidUtf8Rejected = false;
    try {
        ocrBenchmarkCodePoints("\xFF");
    } catch (InvalidArgumentException) {
        $invalidUtf8Rejected = true;
    }
    expectBenchmark($invalidUtf8Rejected, 'Invalid UTF-8 text must be rejected.');
    expectBenchmark(($report['und']['samples'] ?? null) === 1, 'Mixed-language samples must have their own und aggregate bucket.');
    expectBenchmark(($report['und']['cer'] ?? null) === 0.0, 'Exact mixed-language fixtures must have zero CER.');
    expectBenchmark(($report['fr']['samples'] ?? null) === 1, 'French samples must be grouped by language.');
    expectBenchmark(($report['fr']['character_errors'] ?? null) === 1, 'French diacritic loss must count as a character error.');
    expectBenchmark(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'مستند'), 'Benchmark report must not disclose input document text.');
    expectBenchmark(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'synthetic-ar-1'), 'Benchmark report must not disclose sample IDs.');

    $duplicateRows = [
        ['id'=>'duplicate-id','language'=>'en','reference'=>'first','hypothesis'=>'first'],
        ['id'=>'duplicate-id','language'=>'en','reference'=>'second','hypothesis'=>'second'],
    ];
    $duplicateLines = array_map(
        static fn(array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $duplicateRows
    );
    file_put_contents($path, implode(PHP_EOL, $duplicateLines) . PHP_EOL);
    $duplicateIdRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $duplicateIdRejected = str_contains($error->getMessage(), 'Duplicate sample ID');
    }
    expectBenchmark($duplicateIdRejected, 'Duplicate sample IDs must be rejected to prevent accidental double weighting.');

    file_put_contents($path, "{\"id\":\"unsupported-language\",\"language\":\"xx\",\"reference\":\"text\",\"hypothesis\":\"text\"}" . PHP_EOL);
    $unsupportedLanguageRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $unsupportedLanguageRejected = str_contains($error->getMessage(), 'Invalid corpus fields');
    }
    expectBenchmark($unsupportedLanguageRejected, 'Unsupported language tags must be rejected.');

    $unicodeWhitespaceReference = json_encode(
        ['id'=>'whitespace-only','language'=>'en','reference'=>"\u{2003}",'hypothesis'=>''],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    file_put_contents($path, $unicodeWhitespaceReference . PHP_EOL);
    $whitespaceReferenceRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $whitespaceReferenceRejected = str_contains($error->getMessage(), 'Invalid corpus fields');
    }
    expectBenchmark($whitespaceReferenceRejected, 'Unicode-whitespace-only references must be rejected.');

    file_put_contents($path, "{\"id\":\"malformed-json\"");
    $malformedJsonRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $malformedJsonRejected = str_contains($error->getMessage(), 'Invalid JSON on corpus line');
    }
    expectBenchmark($malformedJsonRejected, 'Malformed JSONL rows must be rejected with a line-only error.');

    file_put_contents($path, "{\"id\":\"invalid-utf8\",\"language\":\"en\",\"reference\":\"ok\",\"hypothesis\":\"\xFF\"}" . PHP_EOL);
    $invalidUtf8JsonRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $invalidUtf8JsonRejected = str_contains($error->getMessage(), 'Invalid JSON on corpus line');
    }
    expectBenchmark($invalidUtf8JsonRejected, 'Invalid UTF-8 in JSONL must be rejected without echoing input content.');

    file_put_contents($path, PHP_EOL . "  " . PHP_EOL);
    $emptyCorpusRejected = false;
    try {
        evaluateOcrCorpus($path);
    } catch (RuntimeException $error) {
        $emptyCorpusRejected = str_contains($error->getMessage(), 'no samples');
    }
    expectBenchmark($emptyCorpusRejected, 'An empty corpus must be rejected.');

} finally {
    @unlink($path);
}

echo "OCR corpus benchmark tests passed (Unicode CER/WER aggregation and content-free output)." . PHP_EOL;
