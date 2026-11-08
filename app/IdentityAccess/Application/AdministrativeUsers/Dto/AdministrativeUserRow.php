<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers\Dto;

use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserAccessType;
use App\IdentityAccess\Domain\UserStatus;

final readonly class AdministrativeUserRow
{
    public function __construct(
        public int $userId,
        public int $personId,
        public string $fullName,
        public ?string $identificationNumber,
        public string $loginIdentifier,
        public UserStatus $status,
        public ?string $lastAccessAt,
        public AdministrativeUserAccessType $accessType,
        public ?int $representativeId = null,
    ) {
    }

    public function canChangeStatus(): bool
    {
        return $this->accessType === AdministrativeUserAccessType::Representative
            || $this->accessType === AdministrativeUserAccessType::InstitutionalReporting;
    }

    public function canChangePassword(): bool
    {
        return $this->canChangeStatus();
    }

    public function canChangeLoginIdentifier(): bool
    {
        return $this->accessType === AdministrativeUserAccessType::InstitutionalReporting;
    }
}
