<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

use Closure;

final class GoogleCloudVisionOcrProvider implements OcrProvider
{
    private const MAX_PDF_PAGES = 50;
    private const MAX_BATCH_IMAGES = 16;
    private const MAX_REQUEST_BYTES = 9_000_000;
    private const MAX_IMAGE_BYTES = 6_500_000;

    /** @param callable(string,array<string,string>,string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly GoogleServiceAccountTokenProvider $tokens,
        private readonly string $pdfInfoBinary = 'pdfinfo',
        private readonly string $pdfToPpmBinary = 'pdftoppm',
        private readonly mixed $http = null,
        private readonly string $endpoint = 'https://eu-vision.googleapis.com/v1/images:annotate',
    ) {
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' ||
            !in_array(parse_url($endpoint, PHP_URL_HOST), ['vision.googleapis.com', 'eu-vision.googleapis.com', 'us-vision.googleapis.com'], true)) {
            throw new \InvalidArgumentException('Google Vision endpoint must use the official HTTPS host.');
        }
    }

    public function recognize(OcrRequest $request): OcrResult
    {
        if ($request->mimeType === 'application/pdf') {
            return $this->recognizePdf($request->filePath, $request->heartbeat);
        }

        $bytes = file_get_contents($request->filePath);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new OcrProviderUnavailableException('OCR image input is unavailable or too large for a safe inline request.');
        }

        $responses = $this->annotate([$bytes], $request->heartbeat);
        return $this->parseResponse($responses[0] ?? []);
    }

    private function recognizePdf(string $path, ?Closure $heartbeat = null): OcrResult
    {
        $pageCount = $this->pdfPageCount($path);
        if ($pageCount < 1 || $pageCount > self::MAX_PDF_PAGES) {
            throw new OcrProviderUnavailableException('PDF page count is outside the OCR processing limit.');
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kavoryn-vision-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700)) {
            throw new OcrProviderUnavailableException();
        }

        $pageTexts = [];
        $pageSpans = [];
        $characterOffset = 0;
        $languages = [];

        try {
            $batch = [];
            $batchBytes = 0;
            $flush = function () use (&$batch, &$batchBytes, &$pageTexts, &$pageSpans, &$characterOffset, &$languages, $heartbeat): void {
                if ($batch === []) return;
                $responses = $this->annotate($batch, $heartbeat);
                if (count($responses) !== count($batch)) throw new OcrProviderUnavailableException();

                foreach ($responses as $response) {
                    $result = $this->parseResponse($response);
                    if ($pageTexts !== []) {
                        $characterOffset += 2;
                    }
                    $pageTexts[] = $result->text;
                    foreach ($result->uncertainSpans as $span) {
                        $pageSpans[] = [
                            'start' => $span['start'] + $characterOffset,
                            'end' => $span['end'] + $characterOffset,
                            'confidence' => $span['confidence'],
                            'reason' => $span['reason'],
                        ];
                    }
                    preg_match_all('/./us', $result->text, $chars);
                    $characterOffset += count($chars[0]);
                    if ($result->detectedLanguage !== null && $result->detectedLanguage !== 'und') {
                        $languages[$result->detectedLanguage] = true;
                    }
                }
                $batch = [];
                $batchBytes = 0;
            };

            for ($page = 1; $page <= $pageCount; $page++) {
                if ($heartbeat !== null) $heartbeat();
                $prefix = $directory . DIRECTORY_SEPARATOR . 'page-' . $page;
                $this->runProcess([
                    $this->pdfToPpmBinary, '-f', (string) $page, '-l', (string) $page,
                    '-singlefile', '-jpeg', '-jpegopt', 'quality=82', '-r', '120',
                    $path, $prefix,
                ], 30);

                $imagePath = $prefix . '.jpg';
                $bytes = file_get_contents($imagePath);
                @unlink($imagePath);
                if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                    throw new OcrProviderUnavailableException('A rendered PDF page exceeded the safe OCR image limit.');
                }

                $encoded = base64_encode($bytes);
                $estimated = strlen($encoded) + 512;
                if ($batch !== [] &&
                    (count($batch) >= self::MAX_BATCH_IMAGES || $batchBytes + $estimated > self::MAX_REQUEST_BYTES)) {
                    $flush();
                }
                $batch[] = $bytes;
                $batchBytes += $estimated;
            }
            $flush();
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) @unlink($file);
            }
            @rmdir($directory);
        }

        $detected = count($languages) === 1 ? (string) array_key_first($languages) : 'und';
        return new OcrResult(implode("\n\n", $pageTexts), $pageSpans, $detected);
    }

    /** @param list<string> $images @return list<array<string,mixed>> */
    private function annotate(array $images, ?Closure $heartbeat = null): array
    {
        if ($heartbeat !== null) $heartbeat();
        $requests = [];
        foreach ($images as $bytes) {
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new OcrProviderUnavailableException('OCR image exceeds the safe request limit.');
            }
            $requests[] = [
                'image' => ['content' => base64_encode($bytes)],
                'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
            ];
        }

        $payload = json_encode(['requests' => $requests], JSON_THROW_ON_ERROR);
        if (strlen($payload) > self::MAX_REQUEST_BYTES) {
            throw new OcrProviderUnavailableException('OCR batch exceeds the safe request limit.');
        }

        $token = $this->tokens->accessToken();
        try {
            if ($this->http !== null) {
                $response = ($this->http)($this->endpoint, [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ], $payload);
            } else {
                if (!function_exists('curl_init')) throw new OcrProviderUnavailableException();
                $curl = curl_init($this->endpoint);
                if ($curl === false) throw new OcrProviderUnavailableException();
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $errno !== 0) throw new OcrProviderUnavailableException();
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (OcrProviderUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new OcrProviderUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new OcrProviderUnavailableException();
        }

        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrProviderUnavailableException();
        }

        if (!is_array($decoded) || !is_array($decoded['responses'] ?? null) ||
            !array_is_list($decoded['responses'])) {
            throw new OcrProviderUnavailableException();
        }

        foreach ($decoded['responses'] as $item) {
            if (!is_array($item) || isset($item['error'])) {
                throw new OcrProviderUnavailableException();
            }
        }
        return $decoded['responses'];
    }

    /** @param array<string,mixed> $response */
    private function parseResponse(array $response): OcrResult
    {
        $annotation = $response['fullTextAnnotation'] ?? null;
        $text = is_array($annotation) && is_string($annotation['text'] ?? null)
            ? $annotation['text']
            : '';

        $uncertain = [];
        $cursor = 0;
        $pages = is_array($annotation['pages'] ?? null) ? $annotation['pages'] : [];
        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            foreach (($page['blocks'] ?? []) as $block) {
                foreach (($block['paragraphs'] ?? []) as $paragraph) {
                    foreach (($paragraph['words'] ?? []) as $word) {
                        if (!is_array($word) || !is_array($word['symbols'] ?? null)) continue;
                        $wordText = '';
                        foreach ($word['symbols'] as $symbol) {
                            if (is_array($symbol) && is_string($symbol['text'] ?? null)) {
                                $wordText .= $symbol['text'];
                            }
                        }
                        $confidence = $word['confidence'] ?? null;
                        if ($wordText === '' || (!is_float($confidence) && !is_int($confidence))) continue;
                        $byteOffset = strpos($text, $wordText, $cursor);
                        if ($byteOffset === false) continue;
                        $prefix = substr($text, 0, $byteOffset);
                        preg_match_all('/./us', $prefix, $prefixChars);
                        $start = count($prefixChars[0]);
                        preg_match_all('/./us', $wordText, $wordChars);
                        $length = count($wordChars[0]);
                        if ($confidence < 0.80 && $length > 0) {
                            $uncertain[] = [
                                'start' => $start,
                                'end' => $start + $length,
                                'confidence' => (float) $confidence,
                                'reason' => 'low_confidence',
                            ];
                        }
                        $cursor = $byteOffset + strlen($wordText);
                    }
                }
            }
        }

        $detected = $this->detectLanguage($pages);
        return new OcrResult($text, $uncertain, $detected);
    }

    /** @param list<mixed> $pages */
    private function detectLanguage(array $pages): string
    {
        $found = [];
        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            $this->collectLanguages($page, $found);
        }
        return count($found) === 1 ? (string) array_key_first($found) : 'und';
    }

    /** @param array<string,mixed> $node @param array<string,bool> $found */
    private function collectLanguages(array $node, array &$found): void
    {
        $detected = $node['property']['detectedLanguages'] ?? [];
        if (is_array($detected)) {
            foreach ($detected as $language) {
                $code = is_array($language) ? ($language['languageCode'] ?? null) : null;
                if (is_string($code)) {
                    $base = strtolower(explode('-', $code)[0]);
                    if (in_array($base, ['en','ha','ar','fr'], true)) $found[$base] = true;
                }
            }
        }
        foreach (['blocks','paragraphs','words','symbols'] as $childKey) {
            foreach (($node[$childKey] ?? []) as $child) {
                if (is_array($child)) $this->collectLanguages($child, $found);
            }
        }
    }

    private function pdfPageCount(string $path): int
    {
        $output = $this->runProcess([$this->pdfInfoBinary, $path], 10);
        if (!preg_match('/^Pages:\s+([0-9]+)\s*$/mi', $output, $match)) {
            throw new OcrProviderUnavailableException('PDF page count could not be verified.');
        }
        return (int) $match[1];
    }

    /** @param list<string> $command */
    private function runProcess(array $command, int $timeoutSeconds): string
    {
        $pipes = [];
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) throw new OcrProviderUnavailableException();
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) - $started > $timeoutSeconds) {
                    proc_terminate($process, 9);
                    throw new OcrProviderUnavailableException('PDF rendering timed out.');
                }
                usleep(50_000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $exitCode = $status['exitcode'] ?? -1;
            $closedExit = proc_close($process);
            $process = null;
            if ($exitCode === -1) $exitCode = $closedExit;
            if ($exitCode !== 0) throw new OcrProviderUnavailableException('PDF rendering failed.');
            return $stdout;
        } finally {
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);
            if (is_resource($process)) proc_close($process);
        }
    }
}
