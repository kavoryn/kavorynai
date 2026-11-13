<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$requestId = bin2hex(random_bytes(12));
header('X-Request-Id: ' . $requestId);

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && ($path === '/health' || $path === '/v1/health')) {
    respond(200, [
        'status' => 'ok',
        'service' => 'kavoryn-api',
        'request_id' => $requestId,
    ]);
}

respond(404, [
    'error' => [
        'code' => 'not_found',
        'message' => 'The requested endpoint was not found.',
        'request_id' => $requestId,
    ],
]);
