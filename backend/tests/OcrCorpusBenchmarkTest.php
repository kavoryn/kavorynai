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
    ['id'=>'synthetic-mixed-1','language'=>'und','reference'=>'Kavoryn документ','hypothesis'=>'Kavoryn документ'],
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
    expectBenchmark(($report['en']['word_errors'] ?? null) === 1, 'English word substitutions must count as one word error.');
    expectBenchmark(($report['und']['samples'] ?? null) === 1, 'Mixed-language samples must have their own und aggregate bucket.');
    expectBenchmark(($report['und']['cer'] ?? null) === 0.0, 'Exact mixed-language fixtures must have zero CER.');
    expectBenchmark(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'مستند'), 'Benchmark report must not disclose input document text.');
    expectBenchmark(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'synthetic-ar-1'), 'Benchmark report must not disclose sample IDs.');
} finally {
    @unlink($path);
}

echo "OCR corpus benchmark tests passed (Unicode CER/WER aggregation and content-free output)." . PHP_EOL;
