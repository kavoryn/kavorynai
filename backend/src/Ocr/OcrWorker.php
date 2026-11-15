<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

use Kavoryn\Upload\DocumentStorage;
use Kavoryn\Upload\DocumentFileException;

final class OcrWorker
{
    public function __construct(
        private readonly OcrJobStore $jobs,
        private readonly OcrProvider $provider,
        private readonly OcrResultSink $results,
        private readonly DocumentStorage $storage,
    ) {}

    public function processOne(string $workerId): bool
    {
        $job = $this->jobs->claim($workerId, 600);
        if ($job === null) {
            return false;
        }

        $temporaryPath = null;
        try {
            $temporaryDirectory = realpath(sys_get_temp_dir());
            if ($temporaryDirectory === false || !str_starts_with($temporaryDirectory, DIRECTORY_SEPARATOR)) {
                throw new OcrJobProcessingException('temporary_storage_unavailable');
            }

            $temporaryPath = rtrim($temporaryDirectory, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR . 'kavoryn-ocr-' . bin2hex(random_bytes(16));
            $this->storage->copyToLocalFile($job->storageKey, $temporaryPath);

            if (!is_file($temporaryPath) || !is_readable($temporaryPath) ||
                (fileperms($temporaryPath) & 0777) !== 0600) {
                throw new OcrJobProcessingException('document_file_unavailable');
            }

            $heartbeat = function () use ($job): void {
                if (!$this->jobs->renew($job, 600)) {
                    throw new OcrJobProcessingException('job_lease_lost');
                }
            };

            $result = $this->provider->recognize(new OcrRequest(
                $temporaryPath,
                $job->mimeType,
                $job->language,
                $heartbeat,
            ));

            // Durable encrypted result storage must succeed before the job completes.
            $this->results->store($job, $result);

            if (!$this->jobs->complete($job)) {
                throw new OcrJobProcessingException('job_lease_lost');
            }
        } catch (\Throwable $error) {
            $code = $error instanceof OcrJobProcessingException
                ? $error->errorCode
                : ($error instanceof OcrProviderUnavailableException
                    ? 'ocr_provider_unavailable'
                    : ($error instanceof DocumentFileException ? 'document_file_unavailable' : 'ocr_processing_failed'));

            $delay = min(900, 30 * (2 ** max(0, $job->attempt - 1)));
            $this->jobs->fail($job, $code, $delay);
            if ($error instanceof OcrJobProcessingException && $error->errorCode === 'job_lease_lost') {
                throw $error;
            }
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return true;
    }
}

final class OcrJobProcessingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('OCR job processing failed.');
    }
}
