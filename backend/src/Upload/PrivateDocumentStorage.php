<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class PrivateDocumentStorage implements DocumentStorage, DocumentStorageMaintenance
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
            if (is_resource($output)) {
                fclose($output);
                @unlink($destination);
            }
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

    public function copyToLocalFile(string $storageKey, string $destinationPath): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $storageKey)) {
            throw new DocumentFileException('invalid_storage_key', 'The private storage key is invalid.');
        }
        if ($destinationPath === '' || !str_starts_with($destinationPath, DIRECTORY_SEPARATOR)) {
            throw new DocumentFileException('storage_write_failed', 'The temporary document path is invalid.');
        }

        $root = $this->resolvePrivateRoot();
        $source = $root . DIRECTORY_SEPARATOR . $storageKey;
        if (!is_file($source) || !is_readable($source)) {
            throw new DocumentFileException('document_file_unavailable', 'The private document is unavailable.');
        }

        $input = @fopen($source, 'rb');
        $output = @fopen($destinationPath, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($destinationPath);
            throw new DocumentFileException('storage_unavailable', 'The private document could not be materialized.');
        }

        if (!@chmod($destinationPath, 0600)) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            throw new DocumentFileException('storage_permissions_failed', 'Temporary document permissions could not be secured.');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied === false || !fflush($output) || $copied < 1 ||
                $copied !== filesize($source)) {
                throw new DocumentFileException('storage_write_failed', 'The private document could not be materialized safely.');
            }
        } catch (\Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($destinationPath);
            if ($error instanceof DocumentFileException) throw $error;
            throw new DocumentFileException('storage_write_failed', 'The private document could not be materialized safely.');
        }

        fclose($input);
        fclose($output);
    }

    public function deleteUnreferencedOlderThan(array $referencedStorageKeys, int $olderThanEpoch): int
    {
        if ($olderThanEpoch < 1) {
            throw new \InvalidArgumentException('A valid orphan cleanup cutoff is required.');
        }

        $referenced = array_fill_keys($referencedStorageKeys, true);
        $root = $this->resolvePrivateRoot();
        $deleted = 0;

        foreach (new \\DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isFile()) continue;
            $key = $entry->getFilename();
            if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $key) ||
                $entry->getMTime() >= $olderThanEpoch ||
                isset($referenced[$key])) {
                continue;
            }
            $this->delete($key);
            $deleted++;
        }

        return $deleted;
    }

    public function delete(string $storageKey): bool
    {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $storageKey)) {
            throw new DocumentFileException('invalid_storage_key', 'The private storage key is invalid.');
        }
        $root = $this->resolvePrivateRoot();
        $path = $root . DIRECTORY_SEPARATOR . $storageKey;
        if (!is_file($path)) return true;
        if (!@unlink($path)) {
            throw new DocumentFileException('storage_delete_failed', 'The private document could not be deleted.');
        }
        return true;
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

        $createdDirectory = false;
        if (!is_dir($this->storagePath)) {
            if (!@mkdir($this->storagePath, 0700, true) && !is_dir($this->storagePath)) {
                throw new DocumentFileException('storage_unavailable', 'Private document storage is unavailable.');
            }
            $createdDirectory = true;
        }
        $root = realpath($this->storagePath);
        if ($root === false) {
            throw new DocumentFileException('storage_unavailable', 'Private document storage is unavailable.');
        }

        $publicPrefix = rtrim($public, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($root === $public || str_starts_with($root . DIRECTORY_SEPARATOR, $publicPrefix)) {
            if ($createdDirectory) @rmdir($root);
            throw new DocumentFileException('unsafe_storage_path', 'Private storage must be outside the public web root.');
        }

        if (!@chmod($root, 0700)) {
            if ($createdDirectory) @rmdir($root);
            throw new DocumentFileException('storage_permissions_failed', 'Private storage permissions could not be secured.');
        }

        return $root;
    }
}
