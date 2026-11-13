<?php

declare(strict_types=1);

namespace Kavoryn\Auth;

use RuntimeException;

final class AuthenticationServiceUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The authentication service is temporarily unavailable.');
    }
}
