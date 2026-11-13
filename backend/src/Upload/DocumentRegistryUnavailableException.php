<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

use RuntimeException;

final class DocumentRegistryUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Document registration is temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
