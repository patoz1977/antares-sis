<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\RepresentativeAddressAssignment;
use App\Family\Domain\StudentAddressAssignment;
use App\Family\Domain\ValueObject\AuthorizedPickupAssignmentId;
use App\Family\Domain\ValueObject\EmergencyContactAssignmentId;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RepresentativeAddressAssignmentId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId;
use RuntimeException;

final class InMemoryFamilyApplicationRepository implements FamilyRepository
{
    /** @var array<int, Family> */
    private array $families = [];

    private int $saveCalls = 0;

    private bool $returnWithoutFamilyId = false;

    private bool $returnWithoutPrimaryMembershipId = false;

    private bool $returnWithoutNewRepresentativeMembershipId = false;

    private bool $returnWithoutNewStudentMembershipId = false;

    private bool $returnWrongFamilyId = false;

    private ?string $returnWithoutNewResourceIdentity = null;

    private ?string $omitRequestedMutation = null;

    public function __construct(
        private int $nextFamilyId = 500,
        private int $nextRepresentativeMembershipId = 800,
        private int $nextStudentMembershipId = 1200,
        private int $nextAddressId = 1600,
        private int $nextRepresentativeAddressAssignmentId = 2000,
        private int $nextStudentAddressAssignmentId = 2400,
        private int $nextEmergencyContactId = 2800,
        private int $nextEmergencyContactAssignmentId = 3200,
        private int $nextAuthorizedPickupId = 3600,
        private int $nextAuthorizedPickupAssignmentId = 4000,
    ) {
    }

    public function seed(Family $family): void
    {
        $id = $family->id();
        if ($id === null) {
            throw new RuntimeException('Seeded Family must have an identity.');
        }

        foreach ($family->representatives() as $membership) {
            if ($membership->id() === null) {
                throw new RuntimeException('Seeded Representative membership must have an identity.');
            }
        }
        foreach ($family->students() as $membership) {
            if ($membership->id() === null) {
                throw new RuntimeException('Seeded Student membership must have an identity.');
            }
        }
        foreach (array_merge(
            $family->addresses(),
            $family->representativeAddressAssignments(),
            $family->studentAddressAssignments(),
            $family->emergencyContacts(),
            $family->emergencyContactAssignments(),
            $family->authorizedPickups(),
            $family->authorizedPickupAssignments(),
        ) as $entity) {
            if ($entity->id() === null) {
                throw new RuntimeException('Seeded Family resource or assignment must have an identity.');
            }
        }

        $this->families[$id->value()] = $this->copy($family);
        $this->nextFamilyId = max($this->nextFamilyId, $id->value() + 7);
    }

    public function findById(FamilyId $id): ?Family
    {
        return isset($this->families[$id->value()])
            ? $this->copy($this->families[$id->value()])
            : null;
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        $matches = [];
        foreach ($this->families as $family) {
            foreach ($family->activeRepresentatives() as $membership) {
                if ($membership->representativeId()->equals($representativeId)) {
                    $matches[] = $this->copy($family);
                    break;
                }
            }
        }

        usort(
            $matches,
            static fn (Family $left, Family $right): int =>
                ($left->id()?->value() ?? 0) <=> ($right->id()?->value() ?? 0),
        );

        return $matches;
    }

    public function findActiveByStudentId(StudentId $studentId): ?Family
    {
        $match = null;
        foreach ($this->families as $family) {
            foreach ($family->activeStudents() as $membership) {
                if (!$membership->studentId()->equals($studentId)) {
                    continue;
                }

                if ($match !== null) {
                    throw new RuntimeException('Student has multiple active Families in test storage.');
                }
                $match = $family;
            }
        }

        return $match === null ? null : $this->copy($match);
    }

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
    {
        return $this->findActiveByStudentId($studentId);
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        $family = $this->findById($familyId);
        if ($family === null) {
            return null;
        }
        foreach ($family->activeRepresentatives() as $membership) {
            if ($membership->representativeId()->equals($representativeId)) {
                return $family;
            }
        }

        return null;
    }

    public function save(Family $family): Family
    {
        $this->saveCalls++;
        if ($this->returnWithoutFamilyId) {
            return clone $family;
        }

        $familyId = $family->id() ?? $this->generatedFamilyId();
        $representatives = array_map(
            fn (FamilyRepresentative $membership): FamilyRepresentative =>
                $this->persistRepresentative($membership),
            $family->representatives(),
        );
        $students = array_map(
            fn (FamilyStudent $membership): FamilyStudent => $this->persistStudent($membership),
            $family->students(),
        );
        $addresses = array_map(
            fn (FamilyAddress $resource): FamilyAddress => $this->persistAddress($resource),
            $family->addresses(),
        );
        $representativeAddressAssignments = array_map(
            fn (RepresentativeAddressAssignment $assignment): RepresentativeAddressAssignment =>
                $this->persistRepresentativeAddressAssignment($assignment),
            $family->representativeAddressAssignments(),
        );
        $studentAddressAssignments = array_map(
            fn (StudentAddressAssignment $assignment): StudentAddressAssignment =>
                $this->persistStudentAddressAssignment($assignment),
            $family->studentAddressAssignments(),
        );
        $emergencyContacts = array_map(
            fn (FamilyEmergencyContact $resource): FamilyEmergencyContact =>
                $this->persistEmergencyContact($resource),
            $family->emergencyContacts(),
        );
        $emergencyContactAssignments = array_map(
            fn (EmergencyContactAssignment $assignment): EmergencyContactAssignment =>
                $this->persistEmergencyContactAssignment($assignment),
            $family->emergencyContactAssignments(),
        );
        $authorizedPickups = array_map(
            fn (FamilyAuthorizedPickup $resource): FamilyAuthorizedPickup =>
                $this->persistAuthorizedPickup($resource),
            $family->authorizedPickups(),
        );
        $authorizedPickupAssignments = array_map(
            fn (AuthorizedPickupAssignment $assignment): AuthorizedPickupAssignment =>
                $this->persistAuthorizedPickupAssignment($assignment),
            $family->authorizedPickupAssignments(),
        );
        if ($this->omitRequestedMutation !== null) {
            $original = $this->families[$familyId->value()] ?? null;
            if ($original !== null) {
                [$addresses, $representativeAddressAssignments, $studentAddressAssignments,
                    $emergencyContacts, $emergencyContactAssignments, $authorizedPickups,
                    $authorizedPickupAssignments] = $this->restoreResourceGroup(
                        $this->omitRequestedMutation,
                        $original,
                        $addresses,
                        $representativeAddressAssignments,
                        $studentAddressAssignments,
                        $emergencyContacts,
                        $emergencyContactAssignments,
                        $authorizedPickups,
                        $authorizedPickupAssignments,
                    );
            }
        }
        if ($this->returnWrongFamilyId) {
            $familyId = new FamilyId($familyId->value() + 9999);
        }
        $persisted = Family::reconstitute(
            $familyId,
            $family->displayName(),
            $family->status(),
            $representatives,
            $students,
            $addresses,
            $representativeAddressAssignments,
            $studentAddressAssignments,
            $emergencyContacts,
            $emergencyContactAssignments,
            $authorizedPickups,
            $authorizedPickupAssignments,
        );

        if (!$this->returnWithoutPrimaryMembershipId
            && !$this->returnWithoutNewRepresentativeMembershipId
            && !$this->returnWithoutNewStudentMembershipId
        ) {
            $this->families[$familyId->value()] = $this->copy($persisted);
        }

        return $this->copy($persisted);
    }

    public function saveCalls(): int
    {
        return $this->saveCalls;
    }

    public function returnWithoutFamilyId(): void
    {
        $this->returnWithoutFamilyId = true;
    }

    public function returnWithoutPrimaryMembershipId(): void
    {
        $this->returnWithoutPrimaryMembershipId = true;
    }

    public function returnWithoutNewRepresentativeMembershipId(): void
    {
        $this->returnWithoutNewRepresentativeMembershipId = true;
    }

    public function returnWithoutNewStudentMembershipId(): void
    {
        $this->returnWithoutNewStudentMembershipId = true;
    }

    public function returnWrongFamilyId(): void
    {
        $this->returnWrongFamilyId = true;
    }

    public function returnWithoutNewResourceIdentity(string $resource): void
    {
        $this->returnWithoutNewResourceIdentity = $resource;
    }

    public function omitRequestedMutation(string $resource): void
    {
        $this->omitRequestedMutation = $resource;
    }

    private function persistRepresentative(FamilyRepresentative $membership): FamilyRepresentative
    {
        $id = $membership->id();
        if ($id === null
            && !(($this->returnWithoutPrimaryMembershipId && $membership->isPrimary())
                || ($this->returnWithoutNewRepresentativeMembershipId && !$membership->isPrimary()))
        ) {
            $id = $this->generatedRepresentativeMembershipId();
        }

        return new FamilyRepresentative(
            $id,
            $membership->representativeId(),
            $membership->relationshipTypeId(),
            $membership->isPrimary(),
            $membership->startedAt(),
            $membership->endedAt(),
        );
    }

    private function persistStudent(FamilyStudent $membership): FamilyStudent
    {
        $id = $membership->id();
        if ($id === null && !$this->returnWithoutNewStudentMembershipId) {
            $id = $this->generatedStudentMembershipId();
        }

        return new FamilyStudent(
            $id,
            $membership->studentId(),
            $membership->startedAt(),
            $membership->endedAt(),
        );
    }

    private function persistAddress(FamilyAddress $resource): FamilyAddress
    {
        $id = $resource->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'address') {
            $id = new FamilyAddressId($this->nextAddressId);
            $this->nextAddressId += 17;
        }

        return new FamilyAddress($id, $resource->label(), $resource->address(), $resource->status());
    }

    private function persistRepresentativeAddressAssignment(
        RepresentativeAddressAssignment $assignment,
    ): RepresentativeAddressAssignment {
        $id = $assignment->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'representative_address_assignment') {
            $id = new RepresentativeAddressAssignmentId($this->nextRepresentativeAddressAssignmentId);
            $this->nextRepresentativeAddressAssignmentId += 19;
        }

        return new RepresentativeAddressAssignment(
            $id,
            $assignment->familyAddressId(),
            $assignment->representativeId(),
            $assignment->startedAt(),
            $assignment->endedAt(),
        );
    }

    private function persistStudentAddressAssignment(
        StudentAddressAssignment $assignment,
    ): StudentAddressAssignment {
        $id = $assignment->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'student_address_assignment') {
            $id = new StudentAddressAssignmentId($this->nextStudentAddressAssignmentId);
            $this->nextStudentAddressAssignmentId += 23;
        }

        return new StudentAddressAssignment(
            $id,
            $assignment->familyAddressId(),
            $assignment->studentId(),
            $assignment->startedAt(),
            $assignment->endedAt(),
        );
    }

    private function persistEmergencyContact(FamilyEmergencyContact $resource): FamilyEmergencyContact
    {
        $id = $resource->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'emergency_contact') {
            $id = new FamilyEmergencyContactId($this->nextEmergencyContactId);
            $this->nextEmergencyContactId += 29;
        }

        return new FamilyEmergencyContact(
            $id,
            $resource->names(),
            $resource->relationshipTypeId(),
            $resource->contactInformation(),
            $resource->status(),
        );
    }

    private function persistEmergencyContactAssignment(
        EmergencyContactAssignment $assignment,
    ): EmergencyContactAssignment {
        $id = $assignment->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'emergency_contact_assignment') {
            $id = new EmergencyContactAssignmentId($this->nextEmergencyContactAssignmentId);
            $this->nextEmergencyContactAssignmentId += 31;
        }

        return new EmergencyContactAssignment(
            $id,
            $assignment->familyEmergencyContactId(),
            $assignment->studentId(),
            $assignment->priority(),
            $assignment->startedAt(),
            $assignment->endedAt(),
        );
    }

    private function persistAuthorizedPickup(FamilyAuthorizedPickup $resource): FamilyAuthorizedPickup
    {
        $id = $resource->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'authorized_pickup') {
            $id = new FamilyAuthorizedPickupId($this->nextAuthorizedPickupId);
            $this->nextAuthorizedPickupId += 37;
        }

        return new FamilyAuthorizedPickup(
            $id,
            $resource->names(),
            $resource->relationshipTypeId(),
            $resource->contactInformation(),
            $resource->identification(),
            $resource->status(),
        );
    }

    private function persistAuthorizedPickupAssignment(
        AuthorizedPickupAssignment $assignment,
    ): AuthorizedPickupAssignment {
        $id = $assignment->id();
        if ($id === null && $this->returnWithoutNewResourceIdentity !== 'authorized_pickup_assignment') {
            $id = new AuthorizedPickupAssignmentId($this->nextAuthorizedPickupAssignmentId);
            $this->nextAuthorizedPickupAssignmentId += 41;
        }

        return new AuthorizedPickupAssignment(
            $id,
            $assignment->familyAuthorizedPickupId(),
            $assignment->studentId(),
            $assignment->startedAt(),
            $assignment->endedAt(),
        );
    }

    private function generatedFamilyId(): FamilyId
    {
        $id = new FamilyId($this->nextFamilyId);
        $this->nextFamilyId += 7;

        return $id;
    }

    private function generatedRepresentativeMembershipId(): FamilyRepresentativeId
    {
        $id = new FamilyRepresentativeId($this->nextRepresentativeMembershipId);
        $this->nextRepresentativeMembershipId += 11;

        return $id;
    }

    private function generatedStudentMembershipId(): FamilyStudentId
    {
        $id = new FamilyStudentId($this->nextStudentMembershipId);
        $this->nextStudentMembershipId += 13;

        return $id;
    }

    private function copy(Family $family): Family
    {
        $id = $family->id();
        if ($id === null) {
            return clone $family;
        }

        return Family::reconstitute(
            $id,
            $family->displayName(),
            $family->status(),
            $family->representatives(),
            $family->students(),
            $family->addresses(),
            $family->representativeAddressAssignments(),
            $family->studentAddressAssignments(),
            $family->emergencyContacts(),
            $family->emergencyContactAssignments(),
            $family->authorizedPickups(),
            $family->authorizedPickupAssignments(),
        );
    }

    /**
     * @param list<FamilyAddress> $addresses
     * @param list<RepresentativeAddressAssignment> $representativeAssignments
     * @param list<StudentAddressAssignment> $studentAssignments
     * @param list<FamilyEmergencyContact> $emergencyContacts
     * @param list<EmergencyContactAssignment> $emergencyAssignments
     * @param list<FamilyAuthorizedPickup> $pickups
     * @param list<AuthorizedPickupAssignment> $pickupAssignments
     * @return array{0: list<FamilyAddress>, 1: list<RepresentativeAddressAssignment>,
     *     2: list<StudentAddressAssignment>, 3: list<FamilyEmergencyContact>,
     *     4: list<EmergencyContactAssignment>, 5: list<FamilyAuthorizedPickup>,
     *     6: list<AuthorizedPickupAssignment>}
     */
    private function restoreResourceGroup(
        string $group,
        Family $original,
        array $addresses,
        array $representativeAssignments,
        array $studentAssignments,
        array $emergencyContacts,
        array $emergencyAssignments,
        array $pickups,
        array $pickupAssignments,
    ): array {
        return match ($group) {
            'address' => [$original->addresses(), $representativeAssignments, $studentAssignments,
                $emergencyContacts, $emergencyAssignments, $pickups, $pickupAssignments],
            'representative_address_assignment' => [$addresses, $original->representativeAddressAssignments(),
                $studentAssignments, $emergencyContacts, $emergencyAssignments, $pickups, $pickupAssignments],
            'student_address_assignment' => [$addresses, $representativeAssignments,
                $original->studentAddressAssignments(), $emergencyContacts, $emergencyAssignments,
                $pickups, $pickupAssignments],
            'emergency_contact' => [$addresses, $representativeAssignments, $studentAssignments,
                $original->emergencyContacts(), $emergencyAssignments, $pickups, $pickupAssignments],
            'emergency_contact_assignment' => [$addresses, $representativeAssignments, $studentAssignments,
                $emergencyContacts, $original->emergencyContactAssignments(), $pickups, $pickupAssignments],
            'authorized_pickup' => [$addresses, $representativeAssignments, $studentAssignments,
                $emergencyContacts, $emergencyAssignments, $original->authorizedPickups(), $pickupAssignments],
            'authorized_pickup_assignment' => [$addresses, $representativeAssignments, $studentAssignments,
                $emergencyContacts, $emergencyAssignments, $pickups,
                $original->authorizedPickupAssignments()],
            default => [$addresses, $representativeAssignments, $studentAssignments,
                $emergencyContacts, $emergencyAssignments, $pickups, $pickupAssignments],
        };
    }
}
