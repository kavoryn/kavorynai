<?php

declare(strict_types=1);

namespace Kavoryn\Consent;

use RuntimeException;

final class ConsentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Explicit document-processing consent is required.');
    }
}
