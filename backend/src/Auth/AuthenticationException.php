<?php

declare(strict_types=1);

namespace Kavoryn\Auth;

use RuntimeException;

final class AuthenticationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A valid sign-in session is required.');
    }
}
