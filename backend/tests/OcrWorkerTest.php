<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProviderUnavailableException.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJob.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJobStore.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResultSink.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrWorker.php';

use Kavoryn\Ocr\OcrJob;
use Kavoryn\Ocr\OcrJobStore;
use Kavoryn\Ocr\OcrProvider;
use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\OcrResultSink;
use Kavoryn\Ocr\OcrWorker;

function ocrWorkerExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class TestOcrJobStore implements OcrJobStore
{
    public bool $completed = false;
    public int $renewals = 0;
    public array $failures = [];
    public function __construct(private ?OcrJob $job) {}
    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob { return $this->job; }
    public function renew(OcrJob $job, int $leaseSeconds = 600): bool { $this->renewals++; return true; }
    public function complete(OcrJob $job): bool { $this->completed = true; return true; }
    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool {
        $this->failures[] = [$errorCode, $retryDelaySeconds];
        return true;
    }
}

final class TestOcrProvider implements OcrProvider
{
    public bool $called = false;
    public function recognize(OcrRequest $request): OcrResult {
        $this->called = true;
        if ($request->heartbeat !== null) ($request->heartbeat)();
        return new OcrResult("Ƙarɓi ɗalibi", [], 'ha');
    }
}

final class TestOcrResultSink implements OcrResultSink
{
    public bool $stored = false;
    public function store(OcrJob $job, OcrResult $result): void {
        $this->stored = $result->text === "Ƙarɓi ɗalibi";
    }
}

function testOcrJob(string $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'): OcrJob
{
    return new OcrJob(
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
}

$root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
$key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
file_put_contents($root . '/' . $key, 'fixture');
$jobs = new TestOcrJobStore(testOcrJob($key));
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $root);
    ocrWorkerExpect($worker->processOne('worker-test-0001'), 'Worker should process one available job.');
    ocrWorkerExpect($provider->called, 'Worker must call the OCR provider for a readable private file.');
    ocrWorkerExpect($sink->stored, 'Worker must persist OCR result before completion.');
    ocrWorkerExpect($jobs->completed, 'Worker must complete the job after result persistence.');
    ocrWorkerExpect($jobs->renewals === 1, 'Worker must renew the lease while the provider is processing.');
    ocrWorkerExpect($jobs->failures === [], 'Successful OCR processing must not record a failure.');
} finally {
    unlink($root . '/' . $key);
}
rmdir($root);

$root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
$jobs = new TestOcrJobStore(testOcrJob());
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $root);
    ocrWorkerExpect($worker->processOne('worker-test-0001'), 'Worker should claim and record a missing-file failure.');
    ocrWorkerExpect(!$provider->called, 'Provider must not receive a missing file.');
    ocrWorkerExpect(!$jobs->completed, 'Missing files must not complete jobs.');
    ocrWorkerExpect(($jobs->failures[0][0] ?? null) === 'document_file_unavailable', 'Missing file must record a safe error code.');
} finally {
    rmdir($root);
}

$root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
$jobs = new TestOcrJobStore(null);
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $root);
    ocrWorkerExpect(!$worker->processOne('worker-test-0001'), 'Empty queue should report no work.');
    ocrWorkerExpect(!$provider->called && !$jobs->completed, 'Empty queue must not invoke provider or completion.');
} finally {
    rmdir($root);
}

echo "OCR worker tests passed (success path, persistence-before-completion, missing private file, and empty queue)." . PHP_EOL;
