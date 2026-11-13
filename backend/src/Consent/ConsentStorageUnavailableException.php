<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use RuntimeException;

final class ConsentStorageUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Consent verification is temporarily unavailable.')
    {
        parent::__construct($message);
    }
}
