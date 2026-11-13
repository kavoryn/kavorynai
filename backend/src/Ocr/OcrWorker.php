<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final class OcrWorker
{
    public function __construct(
        private readonly OcrJobStore $jobs,
        private readonly OcrProvider $provider,
        private readonly OcrResultSink $results,
        private readonly string $privateStoragePath,
    ) {
        if ($privateStoragePath === '' || !str_starts_with($privateStoragePath, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('An absolute private document storage path is required.');
        }
    }

    public function processOne(string $workerId): bool
    {
        $job = $this->jobs->claim($workerId);
        if ($job === null) {
            return false;
        }

        try {
            $filePath = $this->resolveFile($job->storageKey);
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new OcrJobProcessingException('document_file_unavailable');
            }

            $result = $this->provider->recognize(new OcrRequest(
                $filePath,
                $job->mimeType,
                $job->language,
            ));

            // The result sink must enforce the approved retention/encryption policy.
            // Never mark a job successful before durable result storage succeeds.
            $this->results->store($job, $result);

            if (!$this->jobs->complete($job)) {
                throw new OcrJobProcessingException('job_lease_lost');
            }
        } catch (\Throwable $error) {
            $code = $error instanceof OcrJobProcessingException
                ? $error->errorCode
                : ($error instanceof OcrProviderUnavailableException ? 'ocr_provider_unavailable' : 'ocr_processing_failed');

            $delay = min(900, 30 * (2 ** max(0, $job->attempt - 1)));
            $this->jobs->fail($job, $code, $delay);
            if ($error instanceof OcrJobProcessingException && $error->errorCode === 'job_lease_lost') {
                throw $error;
            }
        }

        return true;
    }

    private function resolveFile(string $storageKey): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $storageKey)) {
            throw new OcrJobProcessingException('invalid_storage_key');
        }

        $root = realpath($this->privateStoragePath);
        if ($root === false || !is_dir($root)) {
            throw new OcrJobProcessingException('private_storage_unavailable');
        }

        $path = $root . DIRECTORY_SEPARATOR . $storageKey;
        $resolved = realpath($path);
        if ($resolved === false || dirname($resolved) !== $root || !is_file($resolved) || !is_readable($resolved)) {
            throw new OcrJobProcessingException('document_file_unavailable');
        }

        return $resolved;
    }
}

final class OcrJobProcessingException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('OCR job processing failed.');
    }
}
