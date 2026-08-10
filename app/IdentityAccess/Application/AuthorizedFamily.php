<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class AuthorizedFamily
{
    public function __construct(
        public int $familyId,
        public string $displayName,
    ) {
    }
}
