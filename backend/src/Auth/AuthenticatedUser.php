<?php

declare(strict_types=1);

namespace Kavoryn\Auth;

final readonly class AuthenticatedUser
{
    public function __construct(
        public string $id,
        public ?string $email = null,
    ) {}
}
