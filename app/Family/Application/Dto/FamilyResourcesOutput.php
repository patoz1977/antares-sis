<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\RepresentativeAddressAssignment;
use App\Family\Domain\StudentAddressAssignment;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class FamilyResourcesOutput
{
    /**
     * @param list<FamilyAddressOutput> $addresses
     * @param list<RepresentativeAddressAssignmentOutput> $representativeAddressAssignments
     * @param list<StudentAddressAssignmentOutput> $studentAddressAssignments
     * @param list<FamilyEmergencyContactOutput> $emergencyContacts
     * @param list<EmergencyContactAssignmentOutput> $emergencyContactAssignments
     * @param list<FamilyAuthorizedPickupOutput> $authorizedPickups
     * @param list<AuthorizedPickupAssignmentOutput> $authorizedPickupAssignments
     */
    public function __construct(
        public int $familyId,
        public string $displayName,
        public string $status,
        public array $addresses,
        public array $representativeAddressAssignments,
        public array $studentAddressAssignments,
        public array $emergencyContacts,
        public array $emergencyContactAssignments,
        public array $authorizedPickups,
        public array $authorizedPickupAssignments,
    ) {
    }

    public static function fromFamily(Family $family, FamilyId $expectedId): self
    {
        if ($family->id()?->equals($expectedId) !== true) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an invalid persisted Family identity.'
            );
        }

        return new self(
            $expectedId->value(),
            $family->displayName()->value(),
            $family->status()->value,
            array_map(
                static fn (FamilyAddress $item): FamilyAddressOutput => FamilyAddressOutput::fromAddress($item),
                $family->addresses(),
            ),
            array_map(
                static fn (RepresentativeAddressAssignment $item): RepresentativeAddressAssignmentOutput =>
                    RepresentativeAddressAssignmentOutput::fromAssignment($item),
                $family->representativeAddressAssignments(),
            ),
            array_map(
                static fn (StudentAddressAssignment $item): StudentAddressAssignmentOutput =>
                    StudentAddressAssignmentOutput::fromAssignment($item),
                $family->studentAddressAssignments(),
            ),
            array_map(
                static fn (FamilyEmergencyContact $item): FamilyEmergencyContactOutput =>
                    FamilyEmergencyContactOutput::fromContact($item),
                $family->emergencyContacts(),
            ),
            array_map(
                static fn (EmergencyContactAssignment $item): EmergencyContactAssignmentOutput =>
                    EmergencyContactAssignmentOutput::fromAssignment($item),
                $family->emergencyContactAssignments(),
            ),
            array_map(
                static fn (FamilyAuthorizedPickup $item): FamilyAuthorizedPickupOutput =>
                    FamilyAuthorizedPickupOutput::fromPickup($item),
                $family->authorizedPickups(),
            ),
            array_map(
                static fn (AuthorizedPickupAssignment $item): AuthorizedPickupAssignmentOutput =>
                    AuthorizedPickupAssignmentOutput::fromAssignment($item),
                $family->authorizedPickupAssignments(),
            ),
        );
    }
}
