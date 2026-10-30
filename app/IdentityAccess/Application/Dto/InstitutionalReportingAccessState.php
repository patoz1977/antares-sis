<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

use App\IdentityAccess\Domain\UserStatus;

final readonly class InstitutionalReportingAccessState
{
    public const ELIGIBLE_NO_USER = 'ELIGIBLE_NO_USER';
    public const ELIGIBLE_ACTIVE_USER = 'ELIGIBLE_ACTIVE_USER';
    public const ACTIVE_DEDICATED = 'ACTIVE_DEDICATED';
    public const DISABLED_DEDICATED = 'DISABLED_DEDICATED';
    public const INELIGIBLE_REPRESENTATIVE = 'INELIGIBLE_REPRESENTATIVE';
    public const INELIGIBLE_STUDENT = 'INELIGIBLE_STUDENT';
    public const INELIGIBLE_MULTIPLE_ROLES = 'INELIGIBLE_MULTIPLE_ROLES';
    public const EXACT_ADMIN = 'EXACT_ADMIN';
    public const INCOHERENT = 'INCOHERENT';

    public function __construct(
        public int $personId,
        public string $personName,
        public string $state,
        public ?string $loginIdentifier = null,
        public ?UserStatus $userStatus = null,
    ) {
    }
}
