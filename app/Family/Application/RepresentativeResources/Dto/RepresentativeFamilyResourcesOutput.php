<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources\Dto;

use App\Family\Application\Dto\AuthorizedPickupAssignmentOutput;
use App\Family\Application\Dto\EmergencyContactAssignmentOutput;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Dto\RepresentativeAddressAssignmentOutput;
use App\Family\Application\Dto\StudentAddressAssignmentOutput;

final readonly class RepresentativeFamilyResourcesOutput
{
    /**
     * @param list<RepresentativeFamilyStudentOption> $students
     * @param list<FamilyAddressOutput> $addresses
     * @param list<RepresentativeAddressAssignmentOutput> $ownRepresentativeAddressAssignments
     * @param list<StudentAddressAssignmentOutput> $studentAddressAssignments
     * @param list<FamilyEmergencyContactOutput> $emergencyContacts
     * @param list<EmergencyContactAssignmentOutput> $emergencyContactAssignments
     * @param list<FamilyAuthorizedPickupOutput> $authorizedPickups
     * @param list<AuthorizedPickupAssignmentOutput> $authorizedPickupAssignments
     */
    public function __construct(
        public int $familyId,
        public string $familyDisplayName,
        public bool $canChangeFamily,
        public array $students,
        public array $addresses,
        public array $ownRepresentativeAddressAssignments,
        public array $studentAddressAssignments,
        public array $emergencyContacts,
        public array $emergencyContactAssignments,
        public array $authorizedPickups,
        public array $authorizedPickupAssignments,
    ) {
    }
}
