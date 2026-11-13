<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

use RuntimeException;

final class DocumentIdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This retry key was already used for a different document or setting.');
    }
}
