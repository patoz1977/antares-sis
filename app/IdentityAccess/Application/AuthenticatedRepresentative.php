<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class AuthenticatedRepresentative
{
    public function __construct(
        public int $userId,
        public int $personId,
        public int $representativeId,
        public string $loginIdentifier,
    ) {
    }
}
