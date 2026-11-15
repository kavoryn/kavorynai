<?php

declare(strict_types=1);

/**
 * Offline OCR evaluation for a permission-cleared, manually transcribed corpus.
 *
 * JSONL input fields per line:
 *   {"id":"sample-001","language":"ha","reference":"...","hypothesis":"..."}
 *
 * The output contains aggregate counts only. It never prints document text or IDs.
 * Text is compared exactly as Unicode code points; WER tokenizes on whitespace.
 * No case-folding, punctuation stripping, or Unicode normalization is applied.
 */

function ocrBenchmarkCodePoints(string $text): array
{
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) {
        throw new InvalidArgumentException('Input text must be valid UTF-8.');
    }
    return $characters;
}

/** @return list<string> */
function ocrBenchmarkWords(string $text): array
{
    // Treat Unicode separator characters (for example NBSP and em space) as
    // token boundaries as well as ASCII whitespace. Keep tokens themselves exact.
    $words = preg_split('/[\p{Z}\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) {
        throw new InvalidArgumentException('Input text must be valid UTF-8.');
    }
    return $words;
}

/**
 * @param list<string> $reference
 * @param list<string> $hypothesis
 */
function ocrBenchmarkEditDistance(array $reference, array $hypothesis): int
{
    $previous = range(0, count($hypothesis));

    foreach ($reference as $i => $referenceUnit) {
        $current = [$i + 1];
        foreach ($hypothesis as $j => $hypothesisUnit) {
            $substitution = $previous[$j] + ($referenceUnit === $hypothesisUnit ? 0 : 1);
            $deletion = $previous[$j + 1] + 1;
            $insertion = $current[$j] + 1;
            $current[$j + 1] = min($substitution, $deletion, $insertion);
        }
        $previous = $current;
    }

    return $previous[count($hypothesis)];
}

/**
 * @return array<string,array{
 *   samples:int,
 *   reference_characters:int,
 *   character_errors:int,
 *   cer:float,
 *   reference_words:int,
 *   word_errors:int,
 *   wer:float
 * }>
 */
function evaluateOcrCorpus(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The corpus file does not exist or is not readable.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The corpus file could not be opened.');
    }

    $totals = [];
    $seenSampleIds = [];
    $lineNumber = 0;

    try {
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            if (trim($line) === '') {
                continue;
            }

            try {
                $item = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Invalid JSON on corpus line ' . $lineNumber . '.');
            }

            if (!is_array($item) ||
                !is_string($item['id'] ?? null) ||
                trim($item['id']) === '' ||
                !is_string($item['language'] ?? null) ||
                !in_array($item['language'], ['en', 'ha', 'ar', 'fr', 'und'], true) ||
                !is_string($item['reference'] ?? null) ||
                trim($item['reference']) === '' ||
                !is_string($item['hypothesis'] ?? null)) {
                throw new RuntimeException('Invalid corpus fields on line ' . $lineNumber . '.');
            }
            $sampleId = $item['id'];
            if (isset($seenSampleIds[$sampleId])) {
                throw new RuntimeException('Duplicate sample ID on corpus line ' . $lineNumber . '.');
            }
            $seenSampleIds[$sampleId] = true;

            $referenceCharacters = ocrBenchmarkCodePoints($item['reference']);
            $hypothesisCharacters = ocrBenchmarkCodePoints($item['hypothesis']);
            $referenceWords = ocrBenchmarkWords($item['reference']);
            $hypothesisWords = ocrBenchmarkWords($item['hypothesis']);

            $language = $item['language'];
            $totals[$language] ??= [
                'samples' => 0,
                'reference_characters' => 0,
                'character_errors' => 0,
                'reference_words' => 0,
                'word_errors' => 0,
            ];

            $totals[$language]['samples']++;
            $totals[$language]['reference_characters'] += count($referenceCharacters);
            $totals[$language]['character_errors'] += ocrBenchmarkEditDistance(
                $referenceCharacters,
                $hypothesisCharacters
            );
            $totals[$language]['reference_words'] += count($referenceWords);
            $totals[$language]['word_errors'] += ocrBenchmarkEditDistance(
                $referenceWords,
                $hypothesisWords
            );
        }
    } finally {
        fclose($handle);
    }

    if ($totals === []) {
        throw new RuntimeException('The corpus contains no samples.');
    }

    ksort($totals);
    foreach ($totals as &$total) {
        $total['cer'] = $total['character_errors'] / $total['reference_characters'];
        $total['wer'] = $total['reference_words'] > 0
            ? $total['word_errors'] / $total['reference_words']
            : 0.0;
        $total['cer'] = round($total['cer'], 6);
        $total['wer'] = round($total['wer'], 6);
    }
    unset($total);

    return $totals;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    $corpusPath = $argv[1] ?? '';
    if ($corpusPath === '' || in_array($corpusPath, ['-h', '--help'], true)) {
        fwrite(STDERR, "Usage: php backend/tools/ocr_corpus_benchmark.php /path/to/permission-cleared-corpus.jsonl\n");
        exit($corpusPath === '' ? 2 : 0);
    }

    try {
        $report = evaluateOcrCorpus($corpusPath);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
