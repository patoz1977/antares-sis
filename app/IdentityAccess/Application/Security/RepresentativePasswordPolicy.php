<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Security;

use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;

final readonly class RepresentativePasswordPolicy
{
    private const MINIMUM_LENGTH = 5;

    public function assertValid(string $plainTextPassword): void
    {
        if (mb_strlen($plainTextPassword, 'UTF-8') < self::MINIMUM_LENGTH) {
            throw new InvalidRepresentativePassword(
                'Representative password must contain at least five characters.'
            );
        }
    }
}
