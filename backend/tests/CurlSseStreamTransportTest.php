<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ai/ProviderSseEventMapper.php';
require_once dirname(__DIR__) . '/src/Ai/ProviderStreamException.php';
require_once dirname(__DIR__) . '/src/Ai/SseEventDecoder.php';
require_once dirname(__DIR__) . '/src/Ai/CurlSseStreamTransport.php';

use Kavoryn\Ai\CurlSseStreamTransport;
use Kavoryn\Ai\ProviderSseEventMapper;
use Kavoryn\Ai\ProviderStreamException;

function transportExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$transport = new CurlSseStreamTransport(['api.openai.com', 'generativelanguage.googleapis.com']);

$mapper = new class implements ProviderSseEventMapper {
    public function map(array $event): ?array
    {
        return null;
    }
};

foreach ([
    'http://api.openai.com/v1/responses',
    'https://evil.example/v1/responses',
    'https://user:pass@api.openai.com/v1/responses',
    'https://api.openai.com:8443/v1/responses',
    'https://api.openai.com/v1/responses#fragment',
] as $url) {
    try {
        $transport->stream($url, '{}', ['Accept: text/event-stream'], $mapper, static function (array $event): void {});
        throw new RuntimeException('A disallowed provider URL must be rejected.');
    } catch (ProviderStreamException $error) {
        transportExpect(
            $error->getMessage() === 'The AI provider endpoint is not allowed.',
            'Disallowed URLs must fail with a safe generic error.',
        );
    }
}

try {
    $transport->stream(
        'https://api.openai.com/v1/responses',
        '{}',
        ["Authorization: Bearer safe\r\nX-Injected: yes"],
        $mapper,
        static function (array $event): void {},
    );
    throw new RuntimeException('Header injection must be rejected.');
} catch (ProviderStreamException $error) {
    transportExpect(
        $error->getMessage() === 'The AI provider request could not be prepared.',
        'Header injection must fail with a safe generic error.',
    );
}

try {
    new CurlSseStreamTransport(['api.openai.com'], 20, 10);
    throw new RuntimeException('Invalid timeout bounds must be rejected.');
} catch (InvalidArgumentException) {
    // Expected configuration validation.
}

try {
    new CurlSseStreamTransport(['*']);
    throw new RuntimeException('Wildcard provider hosts must be rejected.');
} catch (InvalidArgumentException) {
    // Exact host allowlists only.
}

echo "cURL SSE transport policy tests passed." . PHP_EOL;
