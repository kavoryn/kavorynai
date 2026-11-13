<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

use RuntimeException;

final class DocumentConsentRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The consent token is expired, mismatched, or has already been used.');
    }
}
