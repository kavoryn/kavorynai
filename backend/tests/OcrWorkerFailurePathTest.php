<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Upload/DocumentFileException.php';
require_once dirname(__DIR__) . '/src/Upload/InspectedDocument.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorage.php';
require_once dirname(__DIR__) . '/src/Upload/DocumentStorageMaintenance.php';
require_once dirname(__DIR__) . '/src/Upload/PrivateDocumentStorage.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProviderUnavailableException.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJob.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJobStore.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResultSink.php';
require_once dirname(__DIR__) . '/src/Ocr/SupabaseEncryptedOcrResultSink.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrWorker.php';

use Kavoryn\Ocr\OcrJob;
use Kavoryn\Ocr\OcrJobStore;
use Kavoryn\Ocr\OcrProvider;
use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\OcrResultSink;
use Kavoryn\Ocr\OcrResultStorageException;
use Kavoryn\Ocr\OcrWorker;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\PrivateDocumentStorage;

function failurePathExpect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class FailurePathJobStore implements OcrJobStore
{
    public bool $completed = false;
    public array $failures = [];

    public function __construct(private readonly OcrJob $job) {}
    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob { return $this->job; }
    public function renew(OcrJob $job, int $leaseSeconds = 600): bool { return true; }
    public function complete(OcrJob $job): bool { $this->completed = true; return true; }
    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool {
        $this->failures[] = [$errorCode, $retryDelaySeconds];
        return true;
    }
}

final class FailurePathProvider implements OcrProvider
{
    public function recognize(OcrRequest $request): OcrResult
    {
        if ($request->heartbeat !== null) ($request->heartbeat)();
        return new OcrResult('Gwajin Hausa', [], 'ha');
    }
}

final class FailingResultSink implements OcrResultSink
{
    public function store(OcrJob $job, OcrResult $result): void
    {
        throw new OcrResultStorageException('ocr_result_store_unavailable');
    }
}

$base = sys_get_temp_dir() . '/kavoryn-ocr-storage-failure-' . bin2hex(random_bytes(5));
$private = $base . '/private';
$public = $base . '/public';
mkdir($base, 0700, true);
mkdir($private, 0700);
mkdir($public, 0700);
$source = $base . '/fixture.png';
file_put_contents($source, 'fixture');
$storage = new PrivateDocumentStorage($private, $public);
$key = $storage->store($source, new InspectedDocument('image/png', 'png', 7, hash('sha256', 'fixture')));
$job = new OcrJob(
    'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    'c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12',
    $key,
    'image/png',
    'ha',
    false,
    1,
    'worker-test-0001',
);
$jobs = new FailurePathJobStore($job);

try {
    $worker = new OcrWorker($jobs, new FailurePathProvider(), new FailingResultSink(), $storage);
    failurePathExpect($worker->processOne('worker-test-0001'), 'The claimed job should be handled.');
    failurePathExpect(!$jobs->completed, 'A job must not complete when encrypted result storage fails.');
    failurePathExpect(
        ($jobs->failures[0][0] ?? null) === 'ocr_result_store_unavailable',
        'The worker must preserve the encrypted result storage error code.'
    );
    failurePathExpect(
        ($jobs->failures[0][1] ?? null) === 30,
        'The first failed attempt must use the expected bounded retry delay.'
    );
} finally {
    $storage->delete($key);
    @unlink($source);
    @rmdir($private);
    @rmdir($public);
    @rmdir($base);
}

echo "OCR worker failure-path tests passed." . PHP_EOL;
