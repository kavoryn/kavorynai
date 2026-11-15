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
use Kavoryn\Ocr\OcrJobStore;
use Kavoryn\Ocr\OcrProvider;
use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\OcrResultSink;
use Kavoryn\Ocr\OcrWorker;
use Kavoryn\Upload\InspectedDocument;
use Kavoryn\Upload\PrivateDocumentStorage;

function ocrWorkerExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function ocrTempDir(string $label): string
{
    $path = sys_get_temp_dir() . '/kavoryn-' . $label . '-' . bin2hex(random_bytes(5));
    if (!mkdir($path, 0700, true)) throw new RuntimeException('Could not create OCR test directory.');
    return $path;
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
    public bool $fileReadableDuringRecognition = false;
    public bool $ownerOnlyPermissionsDuringRecognition = false;
    public ?string $observedPath = null;

    public function recognize(OcrRequest $request): OcrResult
    {
        $this->called = true;
        $this->observedPath = $request->filePath;
        $this->fileReadableDuringRecognition = is_file($request->filePath) && is_readable($request->filePath);
        $permissions = is_file($request->filePath) ? (fileperms($request->filePath) & 0777) : 0;
        $this->ownerOnlyPermissionsDuringRecognition = $permissions === 0600;
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

$base = ocrTempDir('ocr-success');
$root = $base . '/private';
$public = $base . '/public';
mkdir($root, 0700);
mkdir($public, 0700);
$source = $base . '/source.png';
file_put_contents($source, 'fixture');
$document = new InspectedDocument('image/png', 'png', 7, hash('sha256', 'fixture'));
$storage = new PrivateDocumentStorage($root, $public);
$key = $storage->store($source, $document);
$jobs = new TestOcrJobStore(testOcrJob($key));
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $storage);
    ocrWorkerExpect($worker->processOne('worker-test-0001'), 'Worker should process one available job.');
    ocrWorkerExpect($provider->called, 'Worker must call the OCR provider for a downloaded private file.');
    ocrWorkerExpect($provider->fileReadableDuringRecognition, 'Temporary file must exist during OCR recognition.');
    ocrWorkerExpect($provider->ownerOnlyPermissionsDuringRecognition, 'Temporary OCR file must have mode 0600.');
    ocrWorkerExpect($sink->stored, 'Worker must persist OCR result before completion.');
    ocrWorkerExpect($jobs->completed, 'Worker must complete the job after result persistence.');
    ocrWorkerExpect($jobs->renewals === 1, 'Worker must renew the lease while the provider is processing.');
    ocrWorkerExpect($jobs->failures === [], 'Successful OCR processing must not record a failure.');
    ocrWorkerExpect(is_string($provider->observedPath) && !file_exists($provider->observedPath), 'Temporary OCR file must be removed after processing.');
} finally {
    $storage->delete($key);
    @unlink($source);
    rmdir($root);
    rmdir($public);
    rmdir($base);
}

$base = ocrTempDir('ocr-missing');
$root = $base . '/private';
$public = $base . '/public';
mkdir($root, 0700);
mkdir($public, 0700);
$storage = new PrivateDocumentStorage($root, $public);
$jobs = new TestOcrJobStore(testOcrJob());
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $storage);
    ocrWorkerExpect($worker->processOne('worker-test-0001'), 'Worker should claim and record a missing-file failure.');
    ocrWorkerExpect(!$provider->called, 'Provider must not receive a missing file.');
    ocrWorkerExpect(!$jobs->completed, 'Missing files must not complete jobs.');
    ocrWorkerExpect(($jobs->failures[0][0] ?? null) === 'document_file_unavailable', 'Missing object must record a safe error code.');
} finally {
    rmdir($root);
    rmdir($public);
    rmdir($base);
}

$base = ocrTempDir('ocr-empty');
$root = $base . '/private';
$public = $base . '/public';
mkdir($root, 0700);
mkdir($public, 0700);
$storage = new PrivateDocumentStorage($root, $public);
$jobs = new TestOcrJobStore(null);
$provider = new TestOcrProvider();
$sink = new TestOcrResultSink();

try {
    $worker = new OcrWorker($jobs, $provider, $sink, $storage);
    ocrWorkerExpect(!$worker->processOne('worker-test-0001'), 'Empty queue should report no work.');
    ocrWorkerExpect(!$provider->called && !$jobs->completed, 'Empty queue must not invoke provider or completion.');
} finally {
    rmdir($root);
    rmdir($public);
    rmdir($base);
}

echo "OCR worker tests passed (private storage download, owner-only temporary file, cleanup, failure path, and empty queue)." . PHP_EOL;
