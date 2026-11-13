<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class DocumentFileInspector
{
    public const MAX_PDF_BYTES = 20 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_IMAGE_PIXELS = 40_000_000;
    public const MIN_IMAGE_WIDTH = 200;
    public const MIN_IMAGE_HEIGHT = 200;
    public const MAX_PDF_PAGES = 50;

    /** @param callable(string):array{exit_code:int,stdout:string,stderr:string}|null $pdfInfoRunner */
    public function __construct(
        private readonly mixed $pdfInfoRunner = null,
        private readonly ?string $pdfInfoBinary = null,
    ) {}

    public function inspect(string $path, string $originalName): InspectedDocument
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new DocumentFileException('invalid_file', 'The uploaded file could not be read.');
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 1) {
            throw new DocumentFileException('empty_file', 'The uploaded file is empty.');
        }

        if (!class_exists(\finfo::class)) {
            throw new DocumentFileException('server_configuration_error', 'File inspection is unavailable.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime)) {
            throw new DocumentFileException('unsupported_file', 'This file type is not supported.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = [
            'application/pdf' => ['pdf', self::MAX_PDF_BYTES],
            'image/jpeg' => ['jpg', self::MAX_IMAGE_BYTES],
            'image/png' => ['png', self::MAX_IMAGE_BYTES],
            'image/webp' => ['webp', self::MAX_IMAGE_BYTES],
        ];

        if (!isset($allowed[$mime])) {
            throw new DocumentFileException('unsupported_file', 'Upload a PDF, JPEG, PNG, or WebP document.');
        }

        [$expectedExtension, $maxBytes] = $allowed[$mime];
        $extensionMatches = $extension === $expectedExtension ||
            ($mime === 'image/jpeg' && $extension === 'jpeg');
        if (!$extensionMatches) {
            throw new DocumentFileException('file_type_mismatch', 'The file extension does not match its actual content.');
        }

        if ($size > $maxBytes) {
            throw new DocumentFileException('file_too_large', 'The uploaded file exceeds the allowed size.');
        }

        $width = null;
        $height = null;
        $pageCount = null;
        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new DocumentFileException('invalid_file', 'The PDF could not be inspected.');
            }
            $header = fread($handle, 8);
            fclose($handle);
            if (!is_string($header) || !preg_match('/^%PDF-[12]\.[0-9]/', $header)) {
                throw new DocumentFileException('invalid_file', 'The uploaded PDF signature is invalid.');
            }
        } else {
            $image = @getimagesize($path);
            if (!is_array($image) || !isset($image[0], $image[1]) ||
                !is_int($image[0]) || !is_int($image[1])) {
                throw new DocumentFileException('invalid_file', 'The image file is damaged or invalid.');
            }
            $width = $image[0];
            $height = $image[1];
            if ($width < self::MIN_IMAGE_WIDTH || $height < self::MIN_IMAGE_HEIGHT) {
                throw new DocumentFileException('image_resolution_too_low', 'The image resolution is too low to read reliably.');
            }
            if ($width > intdiv(self::MAX_IMAGE_PIXELS, max(1, $height))) {
                throw new DocumentFileException('image_dimensions_too_large', 'The image dimensions exceed the safe processing limit.');
            }
        }

        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new DocumentFileException('invalid_file', 'The uploaded file could not be fingerprinted.');
        }

        return new InspectedDocument($mime, $expectedExtension, $size, $digest, $width, $height, $pageCount);
    }

    private function inspectPdfPageCount(string $path): int
    {
        if ($this->pdfInfoRunner !== null) {
            try {
                $result = ($this->pdfInfoRunner)($path);
            } catch (\\Throwable) {
                throw new DocumentFileException('pdf_inspection_failed', 'The PDF could not be safely inspected.');
            }
        } else {
            if (!function_exists('proc_open')) {
                throw new DocumentFileException('server_configuration_error', 'PDF inspection is not configured on this server.');
            }
            $binary = $this->pdfInfoBinary ?? (getenv('PDFINFO_BINARY') ?: 'pdfinfo');
            if ($binary === '' || str_contains($binary, "\\0")) {
                throw new DocumentFileException('server_configuration_error', 'PDF inspection is not configured on this server.');
            }

            $pipes = [];
            $process = @proc_open(
                [$binary, '-isodates', $path],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process)) {
                throw new DocumentFileException('server_configuration_error', 'The PDF inspection utility is unavailable.');
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + 8;
            $exitCode = -1;
            while (true) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    throw new DocumentFileException('pdf_inspection_timeout', 'PDF inspection took too long. Try a smaller or simpler PDF.');
                }
                usleep(50_000);
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedCode = proc_close($process);
            if ($exitCode < 0) $exitCode = $closedCode;
            $result = ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        if (!is_array($result) ||
            !is_int($result['exit_code'] ?? null) ||
            !is_string($result['stdout'] ?? null) ||
            !is_string($result['stderr'] ?? null) ||
            $result['exit_code'] !== 0) {
            throw new DocumentFileException('invalid_pdf', 'The PDF is damaged, encrypted, or cannot be inspected.');
        }
        if (preg_match('/^Encrypted:\\s+yes\\b/im', $result['stdout'])) {
            throw new DocumentFileException('encrypted_pdf', 'Password-protected PDFs are not supported yet.');
        }
        if (!preg_match('/^Pages:\\s*(\\d+)\\s*$/im', $result['stdout'], $matches)) {
            throw new DocumentFileException('invalid_pdf', 'The PDF page count could not be verified.');
        }

        $pages = (int) $matches[1];
        if ($pages < 1) {
            throw new DocumentFileException('invalid_pdf', 'The PDF does not contain any readable pages.');
        }
        if ($pages > self::MAX_PDF_PAGES) {
            throw new DocumentFileException('pdf_page_limit_exceeded', 'The PDF exceeds the 50-page limit.');
        }
        return $pages;
    }
}
