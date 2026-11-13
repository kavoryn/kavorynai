<?php

declare(strict_types=1);

namespace Kavoryn\Ocr;

final class SupabaseOcrJobStore implements OcrJobStore
{
    /** @param callable(string,array<string,string>,?string):array{status:int,body:string}|null $http */
    public function __construct(
        private readonly string $supabaseUrl,
        private readonly string $secretKey,
        private readonly string $serviceRoleJwt,
        private readonly mixed $http = null,
    ) {
        if (parse_url($supabaseUrl, PHP_URL_SCHEME) !== 'https' ||
            $secretKey === '' || $serviceRoleJwt === '') {
            throw new \InvalidArgumentException('OCR job storage is not configured.');
        }
    }

    public function claim(string $workerId, int $leaseSeconds = 120): ?OcrJob
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $workerId) || $leaseSeconds < 30 || $leaseSeconds > 600) {
            throw new \InvalidArgumentException('Invalid OCR worker claim parameters.');
        }

        $rows = $this->rpc('claim_next_ocr_job', [
            'p_worker_id' => $workerId,
            'p_lease_seconds' => $leaseSeconds,
        ]);
        $row = $rows[0] ?? null;
        if ($row === null) return null;

        if (!is_array($row) ||
            !is_string($row['job_id'] ?? null) ||
            !is_string($row['document_id'] ?? null) ||
            !is_string($row['user_id'] ?? null) ||
            !is_string($row['storage_key'] ?? null) ||
            !is_string($row['mime_type'] ?? null) ||
            !is_string($row['output_language'] ?? null) ||
            !is_bool($row['translate'] ?? null) ||
            !is_numeric($row['attempt_count'] ?? null)) {
            throw new OcrJobStoreUnavailableException();
        }

        try {
            return new OcrJob(
                $row['job_id'],
                $row['document_id'],
                $row['user_id'],
                $row['storage_key'],
                $row['mime_type'],
                $row['output_language'],
                $row['translate'],
                (int) $row['attempt_count'],
                $workerId,
            );
        } catch (\InvalidArgumentException) {
            throw new OcrJobStoreUnavailableException();
        }
    }

    public function complete(OcrJob $job): bool
    {
        $rows = $this->rpc('complete_ocr_job', [
            'p_job_id' => $job->id,
            'p_worker_id' => $job->workerId,
        ]);
        return ($rows[0] ?? null) === true;
    }

    public function fail(OcrJob $job, string $errorCode, int $retryDelaySeconds = 60): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $errorCode) ||
            $retryDelaySeconds < 0 || $retryDelaySeconds > 3600) {
            throw new \InvalidArgumentException('Invalid OCR job failure parameters.');
        }

        $rows = $this->rpc('fail_ocr_job', [
            'p_job_id' => $job->id,
            'p_worker_id' => $job->workerId,
            'p_error_code' => $errorCode,
            'p_retry_delay_seconds' => $retryDelaySeconds,
        ]);
        return ($rows[0] ?? null) === true;
    }

    /** @return list<mixed> */
    private function rpc(string $name, array $payload): array
    {
        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/rpc/' . $name;
        $headers = [
            'apikey' => $this->secretKey,
            'Authorization' => 'Bearer ' . $this->serviceRoleJwt,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            if ($this->http !== null) {
                $response = ($this->http)($url, $headers, $json);
            } else {
                if (!function_exists('curl_init')) throw new OcrJobStoreUnavailableException();
                $curl = curl_init($url);
                if ($curl === false) throw new OcrJobStoreUnavailableException();
                $lines = [];
                foreach ($headers as $key => $value) $lines[] = $key . ': ' . $value;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($curl);
                curl_close($curl);
                if ($body === false || $errno !== 0) throw new OcrJobStoreUnavailableException();
                $response = ['status' => $status, 'body' => (string) $body];
            }
        } catch (OcrJobStoreUnavailableException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new OcrJobStoreUnavailableException();
        }

        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) ||
            $response['status'] < 200 || $response['status'] >= 300) {
            throw new OcrJobStoreUnavailableException();
        }

        try {
            $decoded = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OcrJobStoreUnavailableException();
        }

        if (is_bool($decoded)) return [$decoded];
        if (!is_array($decoded)) throw new OcrJobStoreUnavailableException();
        return array_is_list($decoded) ? $decoded : [$decoded];
    }
}

final class OcrJobStoreUnavailableException extends \RuntimeException
{
}
