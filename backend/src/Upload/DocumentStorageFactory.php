<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

final class DocumentStorageFactory
{
    public static function fromEnvironment(string $publicPath): DocumentStorage
    {
        $driver = strtolower(trim(getenv('DOCUMENT_STORAGE_DRIVER') ?: 'supabase'));

        if ($driver === 'supabase') {
            return new SupabasePrivateDocumentStorage(
                getenv('SUPABASE_URL') ?: '',
                getenv('SUPABASE_SECRET_KEY') ?: '',
                '',
                getenv('PRIVATE_DOCUMENT_STORAGE_BUCKET') ?: '',
            );
        }

        if ($driver === 'local') {
            if (strtolower(getenv('APP_ENV') ?: 'production') === 'production' ||
                getenv('VERCEL') === '1') {
                throw new DocumentFileException(
                    'storage_not_configured',
                    'Local document storage is restricted to non-production development.',
                );
            }

            return new PrivateDocumentStorage(
                getenv('PRIVATE_DOCUMENT_STORAGE_PATH') ?: '',
                $publicPath,
            );
        }

        throw new DocumentFileException(
            'storage_not_configured',
            'The configured private document storage driver is unsupported.',
        );
    }
}
