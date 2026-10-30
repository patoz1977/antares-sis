<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

final readonly class ProvisionInstitutionalReportingUserInput
{
    public function __construct(
        public int $personId,
        public string $loginIdentifier,
        public string $plainTextPassword,
    ) {
    }
}
