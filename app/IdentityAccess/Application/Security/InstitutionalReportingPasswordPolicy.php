<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Security;

use App\IdentityAccess\Application\Exception\InvalidInstitutionalReportingPassword;

final readonly class InstitutionalReportingPasswordPolicy
{
    public function assertValid(string $plainTextPassword): void
    {
        if (!FiveCharacterPasswordRule::isSatisfiedBy($plainTextPassword)) {
            throw new InvalidInstitutionalReportingPassword(
                'Institutional reporting password must contain at least five characters.'
            );
        }
    }
}
