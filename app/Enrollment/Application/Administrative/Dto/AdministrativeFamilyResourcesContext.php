<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative\Dto;

use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;

final readonly class AdministrativeFamilyResourcesContext
{
    /**
     * @param list<FamilyEmergencyContactOutput> $emergencyContacts
     * @param list<FamilyAuthorizedPickupOutput> $authorizedPickups
     */
    public function __construct(
        public ?FamilyAddressOutput $studentAddress,
        public array $emergencyContacts,
        public array $authorizedPickups,
    ) {
    }
}
