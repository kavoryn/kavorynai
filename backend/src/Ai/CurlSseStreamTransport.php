<?php

declare(strict_types=1);

namespace Kavoryn\Ai;

/**
 * HTTPS-only, bounded cURL transport for provider Server-Sent Events.
 * Provider URLs must be fixed application configuration, never user input.
 */
final class CurlSseStreamTransport implements ProviderStreamTransport
{
    /** @var list<string> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts Exact provider hostnames, without ports. */
    public function __construct(
        array $allowedHosts,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $totalTimeoutSeconds = 120,
        private readonly int $maxResponseBytes = 2097152,
        private readonly int $maxEventBytes = 65536,
    ) {
        if ($allowedHosts === [] || $connectTimeoutSeconds < 1 || $totalTimeoutSeconds < $connectTimeoutSeconds
            || $maxResponseBytes < 1 || $maxEventBytes < 1 || $maxEventBytes > $maxResponseBytes) {
            throw new \InvalidArgumentException('The stream transport limits are invalid.');
        }

        $this->allowedHosts = array_values(array_unique(array_map(
            static fn (string $host): string => strtolower(rtrim($host, '.')),
            $allowedHosts,
        )));
        foreach ($this->allowedHosts as $host) {
            if ($host === '' || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
                throw new \InvalidArgumentException('The provider host configuration is invalid.');
            }
        }
    }

    /**
     * @param callable(array{type:'delta',text:string}|array{type:'completed'}):void $onEvent
     * @param null|callable():bool $isCancelled
     */
    public function streamRequest(
        ProviderStreamRequest $request,
        callable $onEvent,
        ?callable $isCancelled = null,
    ): void {
        $this->stream($request->url, $request->body, $request->headers, $request->mapper, $onEvent, $isCancelled);
    }

    /**
     * @param list<string> $headers Server-generated HTTP headers; never user supplied.
     * @param callable(array{type:'delta',text:string}|array{type:'completed'}):void $onEvent
     * @param null|callable():bool $isCancelled
     */
    public function stream(
        string $url,
        string $requestBody,
        array $headers,
        ProviderSseEventMapper $mapper,
        callable $onEvent,
        ?callable $isCancelled = null,
    ): void {
        $this->assertAllowedUrl($url);
        $this->assertSafeHeaders($headers);

        if (!function_exists('curl_init')) {
            throw new ProviderStreamException('Secure streaming is unavailable on this server.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ProviderStreamException('The AI provider connection could not be started.');
        }

        $decoder = new SseEventDecoder($this->maxEventBytes);
        $responseBytes = 0;
        $contentType = '';
        $transportFailure = null;
        $callbackFailure = null;

        $dispatch = static function (array $rawEvents) use ($mapper, $onEvent, &$callbackFailure): void {
            foreach ($rawEvents as $rawEvent) {
                $mapped = $mapper->map($rawEvent);
                if ($mapped !== null) {
                    try {
                        $onEvent($mapped);
                    } catch (\Throwable $error) {
                        $callbackFailure = $error;
                        throw new ProviderStreamException('The chat stream could not be delivered.');
                    }
                }
            }
        };

        try {
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $requestBody,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $this->totalTimeoutSeconds,
                // Poll cancellation even while the provider is connected but has not emitted a data chunk.
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function (
                    $curl,
                    float $downloadTotal,
                    float $downloaded,
                    float $uploadTotal,
                    float $uploaded
                ) use ($isCancelled, &$transportFailure): int {
                    if ($isCancelled !== null && $isCancelled()) {
                        $transportFailure = 'cancelled';
                        return 1;
                    }
                    return 0;
                },
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$contentType): int {
                    $length = strlen($line);
                    if (preg_match('/^HTTP\/\S+\s+\d{3}/i', trim($line)) === 1) {
                        $contentType = '';
                    } elseif (stripos($line, 'Content-Type:') === 0) {
                        $contentType = strtolower(trim(substr($line, strlen('Content-Type:'))));
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                    &$responseBytes,
                    &$transportFailure,
                    &$contentType,
                    $decoder,
                    $dispatch,
                    $isCancelled,
                ): int {
                    $length = strlen($chunk);
                    $responseBytes += $length;
                    if ($responseBytes > $this->maxResponseBytes) {
                        $transportFailure = 'limit';
                        return 0;
                    }
                    if ($isCancelled !== null && $isCancelled()) {
                        $transportFailure = 'cancelled';
                        return 0;
                    }
                    if (!str_starts_with($contentType, 'text/event-stream')) {
                        $transportFailure = 'content_type';
                        return 0;
                    }

                    try {
                        $dispatch($decoder->push($chunk));
                    } catch (\Throwable) {
                        $transportFailure = 'event';
                        return 0;
                    }
                    return $length;
                },
            ];

            if (!curl_setopt_array($handle, $options)) {
                throw new ProviderStreamException('The AI provider connection could not be configured.');
            }

            $success = curl_exec($handle);
            $curlError = curl_errno($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($callbackFailure !== null) {
                throw new ProviderStreamException('The chat stream could not be delivered.');
            }
            if ($transportFailure === 'cancelled') {
                throw new ProviderStreamException('The chat response was cancelled.');
            }
            if ($transportFailure === 'limit') {
                throw new ProviderStreamException('The AI provider response exceeded the allowed size.');
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new ProviderStreamException('The AI provider could not complete the response.');
            }
            if ($transportFailure !== null || $success === false || $curlError !== 0) {
                throw new ProviderStreamException('The AI provider stream was interrupted.');
            }
            if (!str_starts_with($contentType, 'text/event-stream')) {
                throw new ProviderStreamException('The AI provider returned an unsupported response.');
            }

            $dispatch($decoder->finish());
        } catch (ProviderStreamException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ProviderStreamException('The AI provider stream was interrupted.');
        } finally {
            curl_close($handle);
        }
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !in_array(strtolower(rtrim((string) $parts['host'], '.')), $this->allowedHosts, true)
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new ProviderStreamException('The AI provider endpoint is not allowed.');
        }
    }

    /** @param list<string> $headers */
    private function assertSafeHeaders(array $headers): void
    {
        foreach ($headers as $header) {
            if (!is_string($header) || str_contains($header, "\r") || str_contains($header, "\n")
                || preg_match('/^[A-Za-z0-9-]+:\s*[^\r\n]*$/D', $header) !== 1) {
                throw new ProviderStreamException('The AI provider request could not be prepared.');
            }
        }
    }
}
