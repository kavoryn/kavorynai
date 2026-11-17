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
require_once dirname(__DIR__) . '/src/Ocr/OcrWorker.php';

use Kavoryn\Ocr\OcrJob;
use Kavoryn\Ocr\OcrJobProcessingException;
use Kavoryn\Ocr\OcrJobStore;
use Kavoryn\Ocr\OcrProvider;
use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\OcrResultSink;
use Kavoryn\Ocr\OcrWorker;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\PrivateDocumentStorage;

function leaseLossExpect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class LeaseLossJobStore implements OcrJobStore
{
    public bool $completed = false;
    public array $failures = [];

    public function __construct(private readonly OcrJob $job) {}
    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob { return $this->job; }
    public function renew(OcrJob $job, int $leaseSeconds = 600): bool { return false; }
    public function complete(OcrJob $job): bool { $this->completed = true; return true; }
    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool {
        $this->failures[] = [$errorCode, $retryDelaySeconds];
        return true;
    }
}

final class LeaseLossProvider implements OcrProvider
{
    public ?string $observedPath = null;

    public function recognize(OcrRequest $request): OcrResult
    {
        $this->observedPath = $request->filePath;
        if ($request->heartbeat !== null) ($request->heartbeat)();
        return new OcrResult('Wannan sakamakon bai kamata ya samu ba.', [], 'ha');
    }
}

final class LeaseLossResultSink implements OcrResultSink
{
    public bool $called = false;

    public function store(OcrJob $job, OcrResult $result): void
    {
        $this->called = true;
    }
}

$base = sys_get_temp_dir() . '/kavoryn-ocr-lease-loss-' . bin2hex(random_bytes(5));
$private = $base . '/private';
$public = $base . '/public';
mkdir($base, 0700, true);
mkdir($private, 0700);
mkdir($public, 0700);
$source = $base . '/fixture.png';
file_put_contents($source, 'fixture');
$storage = new PrivateDocumentStorage($private, $public);
$key = $storage->store(
    $source,
    new InspectedDocument('image/png', 'png', 7, hash('sha256', 'fixture'))
);
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
$jobs = new LeaseLossJobStore($job);
$provider = new LeaseLossProvider();
$sink = new LeaseLossResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $storage);
    $caught = null;

    try {
        $worker->processOne('worker-test-0001');
    } catch (OcrJobProcessingException $error) {
        $caught = $error;
    }

    leaseLossExpect($caught instanceof OcrJobProcessingException, 'A lost lease must be surfaced to the worker process.');
    leaseLossExpect($caught->errorCode === 'job_lease_lost', 'A lost heartbeat must retain the job_lease_lost code.');
    leaseLossExpect(!$jobs->completed, 'A job with a lost lease must never be marked complete.');
    leaseLossExpect(
        ($jobs->failures[0][0] ?? null) === 'job_lease_lost',
        'The worker must record the lost lease using the safe error code.'
    );
    leaseLossExpect(!$sink->called, 'The result must not be persisted after the heartbeat reports a lost lease.');
    leaseLossExpect(
        is_string($provider->observedPath) && !file_exists($provider->observedPath),
        'The temporary OCR file must be removed after the lease-loss exception.'
    );
} finally {
    $storage->delete($key);
    @unlink($source);
    @rmdir($private);
    @rmdir($public);
    @rmdir($base);
}

echo "OCR worker lease-loss regression tests passed." . PHP_EOL;
