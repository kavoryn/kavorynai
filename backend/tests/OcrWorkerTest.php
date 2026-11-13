<?php

declare(strict_types=1);

use Kavoryn\Ocr\OcrJob;
use Kavoryn\Ocr\OcrJobStore;
use Kavoryn\Ocr\OcrProvider;
use Kavoryn\Ocr\OcrRequest;
use Kavoryn\Ocr\OcrResult;
use Kavoryn\Ocr\OcrResultSink;
use Kavoryn\Ocr\OcrWorker;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/Ocr/OcrRequest.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResult.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProvider.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrProviderUnavailableException.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJob.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrJobStore.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrResultSink.php';
require_once dirname(__DIR__) . '/src/Ocr/OcrWorker.php';

final class FakeOcrJobStore implements OcrJobStore
{
    public bool $completed = false;
    public array $failures = [];
    public function __construct(private ?OcrJob $job) {}
    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob { return $this->job; }
    public function complete(OcrJob $job): bool { $this->completed = true; return true; }
    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool {
        $this->failures[] = [$errorCode, $retryDelaySeconds];
        return true;
    }
}

final class FakeOcrProvider implements OcrProvider
{
    public bool $called = false;
    public function recognize(OcrRequest $request): OcrResult {
        $this->called = true;
        return new OcrResult("Ƙarɓi ɗalibi", [], 'ha');
    }
}

final class FakeOcrResultSink implements OcrResultSink
{
    public bool $stored = false;
    public function store(OcrJob $job, OcrResult $result): void {
        $this->stored = $result->text === "Ƙarɓi ɗalibi";
    }
}

final class OcrWorkerTest extends TestCase
{
    private function makeJob(string $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'): OcrJob
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

    public function testProcessesAndPersistsResultBeforeCompletingJob(): void
    {
        $root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
        file_put_contents($root . '/' . $key, 'fixture');
        $jobs = new FakeOcrJobStore($this->makeJob($key));
        $provider = new FakeOcrProvider();
        $sink = new FakeOcrResultSink();

        try {
            $worker = new OcrWorker($jobs, $provider, $sink, $root);
            self::assertTrue($worker->processOne('worker-test-0001'));
            self::assertTrue($provider->called);
            self::assertTrue($sink->stored);
            self::assertTrue($jobs->completed);
            self::assertSame([], $jobs->failures);
        } finally {
            unlink($root . '/' . $key);
            rmdir($root);
        }
    }

    public function testMissingPrivateFileFailsJobWithoutCallingProvider(): void
    {
        $root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        $jobs = new FakeOcrJobStore($this->makeJob());
        $provider = new FakeOcrProvider();
        $sink = new FakeOcrResultSink();

        try {
            $worker = new OcrWorker($jobs, $provider, $sink, $root);
            self::assertTrue($worker->processOne('worker-test-0001'));
            self::assertFalse($provider->called);
            self::assertFalse($jobs->completed);
            self::assertSame('document_file_unavailable', $jobs->failures[0][0]);
        } finally {
            rmdir($root);
        }
    }

    public function testEmptyQueueDoesNotInvokeProvider(): void
    {
        $root = sys_get_temp_dir() . '/kavoryn-ocr-' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        $jobs = new FakeOcrJobStore(null);
        $provider = new FakeOcrProvider();
        $sink = new FakeOcrResultSink();

        try {
            $worker = new OcrWorker($jobs, $provider, $sink, $root);
            self::assertFalse($worker->processOne('worker-test-0001'));
            self::assertFalse($provider->called);
            self::assertFalse($jobs->completed);
        } finally {
            rmdir($root);
        }
    }
}
