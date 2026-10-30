<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Security;

use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;

final readonly class RepresentativePasswordPolicy
{
    public function assertValid(string $plainTextPassword): void
    {
        if (!FiveCharacterPasswordRule::isSatisfiedBy($plainTextPassword)) {
            throw new InvalidRepresentativePassword(
                'Representative password must contain at least five characters.'
            );
        }
    }
}
