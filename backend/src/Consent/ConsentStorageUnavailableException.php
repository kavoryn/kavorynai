<?php

declare(strict_types=1);

namespace Kavoryn\\Consent;

use RuntimeException;

final class ConsentStorageUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Consent verification is temporarily unavailable.');
    }
}
