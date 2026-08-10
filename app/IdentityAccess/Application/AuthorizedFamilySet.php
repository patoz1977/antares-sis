<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class AuthorizedFamilySet
{
    /** @param list<AuthorizedFamily> $families */
    public function __construct(
        public AuthenticatedRepresentative $representative,
        public array $families,
    ) {
    }
}
