<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class PrivateDocumentStorage
{
    public function __construct(
        private readonly string $storagePath,
        private readonly string $publicPath,
    ) {}

    public function store(string $sourcePath, InspectedDocument $document): string
    {
        $root = $this->resolvePrivateRoot();
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new DocumentFileException('invalid_file', 'The uploaded file is unavailable.');
        }

        $key = bin2hex(random_bytes(16)) . '.' . $document->extension;
        $destination = $root . DIRECTORY_SEPARATOR . $key;
        $input = fopen($sourcePath, 'rb');
        $output = @fopen($destination, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new DocumentFileException('storage_unavailable', 'Private document storage is unavailable.');
        }

        if (!@chmod($destination, 0600)) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            throw new DocumentFileException('storage_permissions_failed', 'Private document permissions could not be secured.');
        }
        $hash = hash_init('sha256');
        $written = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new DocumentFileException('storage_write_failed', 'The document could not be stored safely.');
                }
                if ($chunk === '') continue;
                $length = strlen($chunk);
                $written += $length;
                if ($written > $document->sizeBytes) {
                    throw new DocumentFileException('file_changed', 'The uploaded file changed during storage.');
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < $length) {
                    $count = fwrite($output, substr($chunk, $offset));
                    if ($count === false || $count === 0) {
                        throw new DocumentFileException('storage_write_failed', 'The document could not be stored safely.');
                    }
                    $offset += $count;
                }
            }
            if (!fflush($output) || $written !== $document->sizeBytes ||
                !hash_equals($document->sha256, hash_final($hash))) {
                throw new DocumentFileException('file_changed', 'The uploaded file changed during storage.');
            }
        } catch (\Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            if ($error instanceof DocumentFileException) throw $error;
            throw new DocumentFileException('storage_write_failed', 'The document could not be stored safely.');
        }

        fclose($input);
        fclose($output);
        return $key;
    }

    public function delete(string $storageKey): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $storageKey)) return;
        $root = $this->resolvePrivateRoot();
        $path = $root . DIRECTORY_SEPARATOR . $storageKey;
        if (is_file($path)) @unlink($path);
    }

    private function resolvePrivateRoot(): string
    {
        if ($this->storagePath === '' || !str_starts_with($this->storagePath, DIRECTORY_SEPARATOR)) {
            throw new DocumentFileException('storage_not_configured', 'Private document storage is not configured.');
        }

        $public = realpath($this->publicPath);
        if ($public === false) {
            throw new DocumentFileException('storage_not_configured', 'The public web root could not be verified.');
        }

        if (!is_dir($this->storagePath) && !@mkdir($this->storagePath, 0700, true) && !is_dir($this->storagePath)) {
            throw new DocumentFileException('storage_unavailable', 'Private document storage is unavailable.');
        }
        if (!@chmod($this->storagePath, 0700)) {
            throw new DocumentFileException('storage_permissions_failed', 'Private storage permissions could not be secured.');
        }
        $root = realpath($this->storagePath);
        if ($root === false) {
            throw new DocumentFileException('storage_unavailable', 'Private document storage is unavailable.');
        }

        $publicPrefix = rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($root === $public || str_starts_with($root . DIRECTORY_SEPARATOR, $publicPrefix)) {
            throw new DocumentFileException('unsafe_storage_path', 'Private storage must be outside the public web root.');
        }

        return $root;
    }
}
