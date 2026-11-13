<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

interface OcrJobStore
{
    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob;

    public function complete(OcrJob $job): bool;

    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool;
}
