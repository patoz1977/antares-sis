<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public int $personId,
        public string $loginIdentifier,
    ) {
    }
}
