<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final class Family
{
    /** @var list<FamilyRepresentative> */
    private array $representatives;

    /** @var list<FamilyStudent> */
    private array $students;

    /** @var list<FamilyAddress> */
    private array $addresses;

    /** @var list<RepresentativeAddressAssignment> */
    private array $representativeAddressAssignments;

    /** @var list<StudentAddressAssignment> */
    private array $studentAddressAssignments;

    /** @var list<FamilyEmergencyContact> */
    private array $emergencyContacts;

    /** @var list<EmergencyContactAssignment> */
    private array $emergencyContactAssignments;

    /** @var list<FamilyAuthorizedPickup> */
    private array $authorizedPickups;

    /** @var list<AuthorizedPickupAssignment> */
    private array $authorizedPickupAssignments;

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     * @param list<FamilyAddress> $addresses
     * @param list<RepresentativeAddressAssignment> $representativeAddressAssignments
     * @param list<StudentAddressAssignment> $studentAddressAssignments
     * @param list<FamilyEmergencyContact> $emergencyContacts
     * @param list<EmergencyContactAssignment> $emergencyContactAssignments
     * @param list<FamilyAuthorizedPickup> $authorizedPickups
     * @param list<AuthorizedPickupAssignment> $authorizedPickupAssignments
     */
    private function __construct(
        private readonly ?FamilyId $id,
        private DisplayName $displayName,
        private FamilyStatus $status,
        array $representatives,
        array $students,
        array $addresses,
        array $representativeAddressAssignments,
        array $studentAddressAssignments,
        array $emergencyContacts,
        array $emergencyContactAssignments,
        array $authorizedPickups,
        array $authorizedPickupAssignments,
    ) {
        self::assertInvariants(
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

        $this->representatives = self::cloneEntities($representatives);
        $this->students = self::cloneEntities($students);
        $this->addresses = self::cloneEntities($addresses);
        $this->representativeAddressAssignments = self::cloneEntities($representativeAddressAssignments);
        $this->studentAddressAssignments = self::cloneEntities($studentAddressAssignments);
        $this->emergencyContacts = self::cloneEntities($emergencyContacts);
        $this->emergencyContactAssignments = self::cloneEntities($emergencyContactAssignments);
        $this->authorizedPickups = self::cloneEntities($authorizedPickups);
        $this->authorizedPickupAssignments = self::cloneEntities($authorizedPickupAssignments);
    }

    public static function create(
        DisplayName $displayName,
        FamilyStatus $status,
        RepresentativeId $initialRepresentativeId,
        RelationshipTypeId $initialRelationshipTypeId,
        DateTimeImmutable $startedAt,
    ): self {
        $initialRepresentative = new FamilyRepresentative(
            null,
            $initialRepresentativeId,
            $initialRelationshipTypeId,
            true,
            $startedAt,
            null,
        );

        return new self(
            null,
            $displayName,
            $status,
            [$initialRepresentative],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        );
    }

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     * @param list<FamilyAddress> $addresses
     * @param list<RepresentativeAddressAssignment> $representativeAddressAssignments
     * @param list<StudentAddressAssignment> $studentAddressAssignments
     * @param list<FamilyEmergencyContact> $emergencyContacts
     * @param list<EmergencyContactAssignment> $emergencyContactAssignments
     * @param list<FamilyAuthorizedPickup> $authorizedPickups
     * @param list<AuthorizedPickupAssignment> $authorizedPickupAssignments
     */
    public static function reconstitute(
        FamilyId $id,
        DisplayName $displayName,
        FamilyStatus $status,
        array $representatives,
        array $students,
        array $addresses = [],
        array $representativeAddressAssignments = [],
        array $studentAddressAssignments = [],
        array $emergencyContacts = [],
        array $emergencyContactAssignments = [],
        array $authorizedPickups = [],
        array $authorizedPickupAssignments = [],
    ): self {
        return new self(
            $id,
            $displayName,
            $status,
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
    }

    public function id(): ?FamilyId
    {
        return $this->id;
    }

    public function displayName(): DisplayName
    {
        return $this->displayName;
    }

    public function updateDisplayName(DisplayName $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function status(): FamilyStatus
    {
        return $this->status;
    }

    public function activate(): void
    {
        $this->status = FamilyStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = FamilyStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === FamilyStatus::Active;
    }

    /** @return list<FamilyRepresentative> */
    public function representatives(): array
    {
        return self::cloneEntities($this->representatives);
    }

    /** @return list<FamilyStudent> */
    public function students(): array
    {
        return self::cloneEntities($this->students);
    }

    /** @return list<FamilyRepresentative> */
    public function activeRepresentatives(): array
    {
        return self::cloneEntities(array_values(array_filter(
            $this->representatives,
            static fn (FamilyRepresentative $membership): bool => $membership->isActive(),
        )));
    }

    /** @return list<FamilyStudent> */
    public function activeStudents(): array
    {
        return self::cloneEntities(array_values(array_filter(
            $this->students,
            static fn (FamilyStudent $membership): bool => $membership->isActive(),
        )));
    }

    public function primaryRepresentative(): FamilyRepresentative
    {
        foreach ($this->representatives as $membership) {
            if ($membership->isActive() && $membership->isPrimary()) {
                return clone $membership;
            }
        }

        throw new InvalidFamilyState('Family has no active primary representative.');
    }

    public function addRepresentative(
        RepresentativeId $representativeId,
        RelationshipTypeId $relationshipTypeId,
        DateTimeImmutable $startedAt,
    ): FamilyRepresentative {
        if ($this->activeRepresentativeIndex($representativeId) !== null) {
            throw new InvalidFamilyState('Representative already has an active Family membership.');
        }

        $membership = new FamilyRepresentative(
            null,
            $representativeId,
            $relationshipTypeId,
            false,
            $startedAt,
            null,
        );
        $this->representatives[] = $membership;

        return clone $membership;
    }

    public function addStudent(StudentId $studentId, DateTimeImmutable $startedAt): FamilyStudent
    {
        if ($this->activeStudentIndex($studentId) !== null) {
            throw new InvalidFamilyState('Student already has an active membership in this Family.');
        }

        $membership = new FamilyStudent(null, $studentId, $startedAt, null);
        $this->students[] = $membership;

        return clone $membership;
    }

    public function endRepresentativeMembership(
        RepresentativeId $representativeId,
        DateTimeImmutable $endedAt,
    ): void {
        $index = $this->activeRepresentativeIndex($representativeId);
        if ($index === null) {
            throw new InvalidFamilyState('Active FamilyRepresentative membership was not found.');
        }

        $membership = $this->representatives[$index];
        if (count($this->activeRepresentatives()) <= 1) {
            throw new InvalidFamilyState('Family must retain at least one active representative.');
        }

        if ($membership->isPrimary()) {
            throw new InvalidFamilyState(
                'Primary representative cannot be ended without an approved atomic replacement.'
            );
        }

        if ($this->hasActiveRepresentativeAddressAssignment($representativeId)) {
            throw new InvalidFamilyState('Representative address assignment must be ended before membership.');
        }

        $membership->end($endedAt);
    }

    public function endStudentMembership(StudentId $studentId, DateTimeImmutable $endedAt): void
    {
        $index = $this->activeStudentIndex($studentId);
        if ($index === null) {
            throw new InvalidFamilyState('Active FamilyStudent membership was not found.');
        }

        if ($this->hasAnyActiveStudentResourceAssignment($studentId)) {
            throw new InvalidFamilyState('Student resource assignments must be ended before membership.');
        }

        $this->students[$index]->end($endedAt);
    }

    /** @return list<FamilyAddress> */
    public function addresses(): array
    {
        return self::cloneEntities($this->addresses);
    }

    /** @return list<FamilyAddress> */
    public function activeAddresses(): array
    {
        return self::cloneEntities(array_values(array_filter(
            $this->addresses,
            static fn (FamilyAddress $address): bool => $address->isActive(),
        )));
    }

    public function addAddress(
        AddressLabel $label,
        Address $address,
        FamilyResourceStatus $status = FamilyResourceStatus::Active,
    ): FamilyAddress {
        $resource = new FamilyAddress(null, $label, $address, $status);
        $this->addresses[] = $resource;

        return clone $resource;
    }

    public function updateAddress(FamilyAddressId $id, AddressLabel $label, Address $address): void
    {
        $this->addresses[$this->requiredAddressIndex($id)]->update($label, $address);
    }

    public function activateAddress(FamilyAddressId $id): void
    {
        $this->addresses[$this->requiredAddressIndex($id)]->activate();
    }

    public function deactivateAddress(FamilyAddressId $id): void
    {
        if ($this->hasActiveAddressAssignment($id)) {
            throw new InvalidFamilyState('Assigned FamilyAddress cannot be deactivated.');
        }

        $this->addresses[$this->requiredAddressIndex($id)]->deactivate();
    }

    /** @return list<RepresentativeAddressAssignment> */
    public function representativeAddressAssignments(): array
    {
        return self::cloneEntities($this->representativeAddressAssignments);
    }

    /** @return list<StudentAddressAssignment> */
    public function studentAddressAssignments(): array
    {
        return self::cloneEntities($this->studentAddressAssignments);
    }

    public function assignAddressToRepresentative(
        RepresentativeId $representativeId,
        FamilyAddressId $familyAddressId,
        DateTimeImmutable $startedAt,
    ): RepresentativeAddressAssignment {
        $this->requireActiveRepresentative($representativeId);
        $address = $this->addresses[$this->requiredAddressIndex($familyAddressId)];
        if (!$address->isActive()) {
            throw new InvalidFamilyState('Inactive FamilyAddress cannot receive a new assignment.');
        }

        $activeIndex = $this->activeRepresentativeAddressAssignmentIndex($representativeId);
        if ($activeIndex !== null
            && $this->representativeAddressAssignments[$activeIndex]->familyAddressId()->equals($familyAddressId)
        ) {
            throw new InvalidFamilyState('FamilyAddress is already active for this Representative.');
        }

        $assignment = new RepresentativeAddressAssignment(
            null,
            $familyAddressId,
            $representativeId,
            $startedAt,
            null,
        );
        $this->assertRepresentativeAddressHistoryStartAvailable($assignment);

        if ($activeIndex !== null) {
            $this->representativeAddressAssignments[$activeIndex]->end($assignment->startedAt());
        }

        $this->representativeAddressAssignments[] = $assignment;

        return clone $assignment;
    }

    public function assignAddressToStudent(
        StudentId $studentId,
        FamilyAddressId $familyAddressId,
        DateTimeImmutable $startedAt,
    ): StudentAddressAssignment {
        $this->requireActiveStudent($studentId);
        $address = $this->addresses[$this->requiredAddressIndex($familyAddressId)];
        if (!$address->isActive()) {
            throw new InvalidFamilyState('Inactive FamilyAddress cannot receive a new assignment.');
        }

        $activeIndex = $this->activeStudentAddressAssignmentIndex($studentId);
        if ($activeIndex !== null
            && $this->studentAddressAssignments[$activeIndex]->familyAddressId()->equals($familyAddressId)
        ) {
            throw new InvalidFamilyState('FamilyAddress is already active for this Student.');
        }

        $assignment = new StudentAddressAssignment(null, $familyAddressId, $studentId, $startedAt, null);
        $this->assertStudentAddressHistoryStartAvailable($assignment);

        if ($activeIndex !== null) {
            $this->studentAddressAssignments[$activeIndex]->end($assignment->startedAt());
        }

        $this->studentAddressAssignments[] = $assignment;

        return clone $assignment;
    }

    public function endRepresentativeAddressAssignment(
        RepresentativeId $representativeId,
        DateTimeImmutable $endedAt,
    ): void {
        $index = $this->activeRepresentativeAddressAssignmentIndex($representativeId);
        if ($index === null) {
            throw new InvalidFamilyState('Active Representative address assignment was not found.');
        }

        $this->representativeAddressAssignments[$index]->end($endedAt);
    }

    public function endStudentAddressAssignment(StudentId $studentId, DateTimeImmutable $endedAt): void
    {
        $index = $this->activeStudentAddressAssignmentIndex($studentId);
        if ($index === null) {
            throw new InvalidFamilyState('Active Student address assignment was not found.');
        }

        $this->studentAddressAssignments[$index]->end($endedAt);
    }

    /** @return list<FamilyEmergencyContact> */
    public function emergencyContacts(): array
    {
        return self::cloneEntities($this->emergencyContacts);
    }

    /** @return list<FamilyEmergencyContact> */
    public function activeEmergencyContacts(): array
    {
        return self::cloneEntities(array_values(array_filter(
            $this->emergencyContacts,
            static fn (FamilyEmergencyContact $contact): bool => $contact->isActive(),
        )));
    }

    public function addEmergencyContact(
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        EmergencyContactInformation $contactInformation,
        FamilyResourceStatus $status = FamilyResourceStatus::Active,
    ): FamilyEmergencyContact {
        $resource = new FamilyEmergencyContact(
            null,
            $names,
            $relationshipTypeId,
            $contactInformation,
            $status,
        );
        $this->emergencyContacts[] = $resource;

        return clone $resource;
    }

    public function updateEmergencyContact(
        FamilyEmergencyContactId $id,
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        EmergencyContactInformation $contactInformation,
    ): void {
        $this->emergencyContacts[$this->requiredEmergencyContactIndex($id)]->update(
            $names,
            $relationshipTypeId,
            $contactInformation,
        );
    }

    public function activateEmergencyContact(FamilyEmergencyContactId $id): void
    {
        $this->emergencyContacts[$this->requiredEmergencyContactIndex($id)]->activate();
    }

    public function deactivateEmergencyContact(FamilyEmergencyContactId $id): void
    {
        if ($this->hasActiveEmergencyContactAssignment($id)) {
            throw new InvalidFamilyState('Assigned FamilyEmergencyContact cannot be deactivated.');
        }

        $this->emergencyContacts[$this->requiredEmergencyContactIndex($id)]->deactivate();
    }

    /** @return list<EmergencyContactAssignment> */
    public function emergencyContactAssignments(): array
    {
        return self::cloneEntities($this->emergencyContactAssignments);
    }

    public function assignEmergencyContactToStudent(
        StudentId $studentId,
        FamilyEmergencyContactId $familyEmergencyContactId,
        ?EmergencyContactPriority $priority,
        DateTimeImmutable $startedAt,
    ): EmergencyContactAssignment {
        $this->requireActiveStudent($studentId);
        $contact = $this->emergencyContacts[$this->requiredEmergencyContactIndex($familyEmergencyContactId)];
        if (!$contact->isActive()) {
            throw new InvalidFamilyState('Inactive FamilyEmergencyContact cannot receive a new assignment.');
        }

        foreach ($this->emergencyContactAssignments as $existing) {
            if (!$existing->isActive() || !$existing->studentId()->equals($studentId)) {
                continue;
            }

            if ($existing->familyEmergencyContactId()->equals($familyEmergencyContactId)) {
                throw new InvalidFamilyState('Emergency contact already has an active assignment to this Student.');
            }

            if ($priority !== null
                && $existing->priority() !== null
                && $existing->priority()->equals($priority)
            ) {
                throw new InvalidFamilyState('Emergency contact priority is already active for this Student.');
            }
        }

        $assignment = new EmergencyContactAssignment(
            null,
            $familyEmergencyContactId,
            $studentId,
            $priority,
            $startedAt,
            null,
        );
        $this->assertEmergencyContactHistoryStartAvailable($assignment);
        $this->emergencyContactAssignments[] = $assignment;

        return clone $assignment;
    }

    public function endEmergencyContactAssignment(
        StudentId $studentId,
        FamilyEmergencyContactId $familyEmergencyContactId,
        DateTimeImmutable $endedAt,
    ): void {
        foreach ($this->emergencyContactAssignments as $assignment) {
            if ($assignment->isActive()
                && $assignment->studentId()->equals($studentId)
                && $assignment->familyEmergencyContactId()->equals($familyEmergencyContactId)
            ) {
                $assignment->end($endedAt);

                return;
            }
        }

        throw new InvalidFamilyState('Active EmergencyContact assignment was not found.');
    }

    /** @return list<FamilyAuthorizedPickup> */
    public function authorizedPickups(): array
    {
        return self::cloneEntities($this->authorizedPickups);
    }

    /** @return list<FamilyAuthorizedPickup> */
    public function activeAuthorizedPickups(): array
    {
        return self::cloneEntities(array_values(array_filter(
            $this->authorizedPickups,
            static fn (FamilyAuthorizedPickup $pickup): bool => $pickup->isActive(),
        )));
    }

    public function addAuthorizedPickup(
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        AuthorizedPickupInformation $contactInformation,
        ?PickupIdentification $identification,
        FamilyResourceStatus $status = FamilyResourceStatus::Active,
    ): FamilyAuthorizedPickup {
        $resource = new FamilyAuthorizedPickup(
            null,
            $names,
            $relationshipTypeId,
            $contactInformation,
            $identification,
            $status,
        );
        $this->authorizedPickups[] = $resource;

        return clone $resource;
    }

    public function updateAuthorizedPickup(
        FamilyAuthorizedPickupId $id,
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        AuthorizedPickupInformation $contactInformation,
        ?PickupIdentification $identification,
    ): void {
        $this->authorizedPickups[$this->requiredAuthorizedPickupIndex($id)]->update(
            $names,
            $relationshipTypeId,
            $contactInformation,
            $identification,
        );
    }

    public function activateAuthorizedPickup(FamilyAuthorizedPickupId $id): void
    {
        $this->authorizedPickups[$this->requiredAuthorizedPickupIndex($id)]->activate();
    }

    public function deactivateAuthorizedPickup(FamilyAuthorizedPickupId $id): void
    {
        if ($this->hasActiveAuthorizedPickupAssignment($id)) {
            throw new InvalidFamilyState('Assigned FamilyAuthorizedPickup cannot be deactivated.');
        }

        $this->authorizedPickups[$this->requiredAuthorizedPickupIndex($id)]->deactivate();
    }

    /** @return list<AuthorizedPickupAssignment> */
    public function authorizedPickupAssignments(): array
    {
        return self::cloneEntities($this->authorizedPickupAssignments);
    }

    public function assignAuthorizedPickupToStudent(
        StudentId $studentId,
        FamilyAuthorizedPickupId $familyAuthorizedPickupId,
        DateTimeImmutable $startedAt,
    ): AuthorizedPickupAssignment {
        $this->requireActiveStudent($studentId);
        $pickup = $this->authorizedPickups[$this->requiredAuthorizedPickupIndex($familyAuthorizedPickupId)];
        if (!$pickup->isActive()) {
            throw new InvalidFamilyState('Inactive FamilyAuthorizedPickup cannot receive a new assignment.');
        }

        foreach ($this->authorizedPickupAssignments as $existing) {
            if ($existing->isActive()
                && $existing->studentId()->equals($studentId)
                && $existing->familyAuthorizedPickupId()->equals($familyAuthorizedPickupId)
            ) {
                throw new InvalidFamilyState('Authorized pickup already has an active assignment to this Student.');
            }
        }

        $assignment = new AuthorizedPickupAssignment(
            null,
            $familyAuthorizedPickupId,
            $studentId,
            $startedAt,
            null,
        );
        $this->assertAuthorizedPickupHistoryStartAvailable($assignment);
        $this->authorizedPickupAssignments[] = $assignment;

        return clone $assignment;
    }

    public function endAuthorizedPickupAssignment(
        StudentId $studentId,
        FamilyAuthorizedPickupId $familyAuthorizedPickupId,
        DateTimeImmutable $endedAt,
    ): void {
        foreach ($this->authorizedPickupAssignments as $assignment) {
            if ($assignment->isActive()
                && $assignment->studentId()->equals($studentId)
                && $assignment->familyAuthorizedPickupId()->equals($familyAuthorizedPickupId)
            ) {
                $assignment->end($endedAt);

                return;
            }
        }

        throw new InvalidFamilyState('Active AuthorizedPickup assignment was not found.');
    }

    private function activeRepresentativeIndex(RepresentativeId $representativeId): ?int
    {
        foreach ($this->representatives as $index => $membership) {
            if ($membership->isActive() && $membership->representativeId()->equals($representativeId)) {
                return $index;
            }
        }

        return null;
    }

    private function activeStudentIndex(StudentId $studentId): ?int
    {
        foreach ($this->students as $index => $membership) {
            if ($membership->isActive() && $membership->studentId()->equals($studentId)) {
                return $index;
            }
        }

        return null;
    }

    private function requireActiveRepresentative(RepresentativeId $representativeId): void
    {
        if ($this->activeRepresentativeIndex($representativeId) === null) {
            throw new InvalidFamilyState('Representative must have an active membership in this Family.');
        }
    }

    private function requireActiveStudent(StudentId $studentId): void
    {
        if ($this->activeStudentIndex($studentId) === null) {
            throw new InvalidFamilyState('Student must have an active membership in this Family.');
        }
    }

    private function requiredAddressIndex(FamilyAddressId $id): int
    {
        foreach ($this->addresses as $index => $address) {
            if ($address->id()?->equals($id) === true) {
                return $index;
            }
        }

        throw new InvalidFamilyState('FamilyAddress does not belong to this Family.');
    }

    private function requiredEmergencyContactIndex(FamilyEmergencyContactId $id): int
    {
        foreach ($this->emergencyContacts as $index => $contact) {
            if ($contact->id()?->equals($id) === true) {
                return $index;
            }
        }

        throw new InvalidFamilyState('FamilyEmergencyContact does not belong to this Family.');
    }

    private function requiredAuthorizedPickupIndex(FamilyAuthorizedPickupId $id): int
    {
        foreach ($this->authorizedPickups as $index => $pickup) {
            if ($pickup->id()?->equals($id) === true) {
                return $index;
            }
        }

        throw new InvalidFamilyState('FamilyAuthorizedPickup does not belong to this Family.');
    }

    private function activeRepresentativeAddressAssignmentIndex(RepresentativeId $representativeId): ?int
    {
        foreach ($this->representativeAddressAssignments as $index => $assignment) {
            if ($assignment->isActive() && $assignment->representativeId()->equals($representativeId)) {
                return $index;
            }
        }

        return null;
    }

    private function activeStudentAddressAssignmentIndex(StudentId $studentId): ?int
    {
        foreach ($this->studentAddressAssignments as $index => $assignment) {
            if ($assignment->isActive() && $assignment->studentId()->equals($studentId)) {
                return $index;
            }
        }

        return null;
    }

    private function hasActiveRepresentativeAddressAssignment(RepresentativeId $representativeId): bool
    {
        return $this->activeRepresentativeAddressAssignmentIndex($representativeId) !== null;
    }

    private function hasAnyActiveStudentResourceAssignment(StudentId $studentId): bool
    {
        foreach ($this->studentAddressAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->studentId()->equals($studentId)) {
                return true;
            }
        }

        foreach ($this->emergencyContactAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->studentId()->equals($studentId)) {
                return true;
            }
        }

        foreach ($this->authorizedPickupAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->studentId()->equals($studentId)) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveAddressAssignment(FamilyAddressId $id): bool
    {
        foreach ($this->representativeAddressAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->familyAddressId()->equals($id)) {
                return true;
            }
        }

        foreach ($this->studentAddressAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->familyAddressId()->equals($id)) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveEmergencyContactAssignment(FamilyEmergencyContactId $id): bool
    {
        foreach ($this->emergencyContactAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->familyEmergencyContactId()->equals($id)) {
                return true;
            }
        }

        return false;
    }

    private function hasActiveAuthorizedPickupAssignment(FamilyAuthorizedPickupId $id): bool
    {
        foreach ($this->authorizedPickupAssignments as $assignment) {
            if ($assignment->isActive() && $assignment->familyAuthorizedPickupId()->equals($id)) {
                return true;
            }
        }

        return false;
    }

    private function assertRepresentativeAddressHistoryStartAvailable(
        RepresentativeAddressAssignment $candidate,
    ): void {
        foreach ($this->representativeAddressAssignments as $existing) {
            if ($existing->representativeId()->equals($candidate->representativeId())
                && $existing->startedAt() == $candidate->startedAt()
            ) {
                throw new InvalidFamilyState('Representative address history already uses this start date.');
            }
        }
    }

    private function assertStudentAddressHistoryStartAvailable(StudentAddressAssignment $candidate): void
    {
        foreach ($this->studentAddressAssignments as $existing) {
            if ($existing->studentId()->equals($candidate->studentId())
                && $existing->startedAt() == $candidate->startedAt()
            ) {
                throw new InvalidFamilyState('Student address history already uses this start date.');
            }
        }
    }

    private function assertEmergencyContactHistoryStartAvailable(EmergencyContactAssignment $candidate): void
    {
        foreach ($this->emergencyContactAssignments as $existing) {
            if ($existing->studentId()->equals($candidate->studentId())
                && $existing->familyEmergencyContactId()->equals($candidate->familyEmergencyContactId())
                && $existing->startedAt() == $candidate->startedAt()
            ) {
                throw new InvalidFamilyState('Emergency contact history already uses this start date.');
            }
        }
    }

    private function assertAuthorizedPickupHistoryStartAvailable(AuthorizedPickupAssignment $candidate): void
    {
        foreach ($this->authorizedPickupAssignments as $existing) {
            if ($existing->studentId()->equals($candidate->studentId())
                && $existing->familyAuthorizedPickupId()->equals($candidate->familyAuthorizedPickupId())
                && $existing->startedAt() == $candidate->startedAt()
            ) {
                throw new InvalidFamilyState('Authorized pickup history already uses this start date.');
            }
        }
    }

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     * @param list<FamilyAddress> $addresses
     * @param list<RepresentativeAddressAssignment> $representativeAddressAssignments
     * @param list<StudentAddressAssignment> $studentAddressAssignments
     * @param list<FamilyEmergencyContact> $emergencyContacts
     * @param list<EmergencyContactAssignment> $emergencyContactAssignments
     * @param list<FamilyAuthorizedPickup> $authorizedPickups
     * @param list<AuthorizedPickupAssignment> $authorizedPickupAssignments
     */
    private static function assertInvariants(
        array $representatives,
        array $students,
        array $addresses,
        array $representativeAddressAssignments,
        array $studentAddressAssignments,
        array $emergencyContacts,
        array $emergencyContactAssignments,
        array $authorizedPickups,
        array $authorizedPickupAssignments,
    ): void {
        self::assertMembershipInvariants($representatives, $students);
        self::assertCollectionType($addresses, FamilyAddress::class, 'Family addresses');
        self::assertCollectionType(
            $representativeAddressAssignments,
            RepresentativeAddressAssignment::class,
            'Representative address assignments',
        );
        self::assertCollectionType(
            $studentAddressAssignments,
            StudentAddressAssignment::class,
            'Student address assignments',
        );
        self::assertCollectionType($emergencyContacts, FamilyEmergencyContact::class, 'Emergency contacts');
        self::assertCollectionType(
            $emergencyContactAssignments,
            EmergencyContactAssignment::class,
            'Emergency contact assignments',
        );
        self::assertCollectionType($authorizedPickups, FamilyAuthorizedPickup::class, 'Authorized pickups');
        self::assertCollectionType(
            $authorizedPickupAssignments,
            AuthorizedPickupAssignment::class,
            'Authorized pickup assignments',
        );

        $representativeIds = [];
        $activeRepresentativeIds = [];
        foreach ($representatives as $membership) {
            $value = $membership->representativeId()->value();
            $representativeIds[$value] = true;
            if ($membership->isActive()) {
                $activeRepresentativeIds[$value] = true;
            }
        }

        $studentIds = [];
        $activeStudentIds = [];
        foreach ($students as $membership) {
            $value = $membership->studentId()->value();
            $studentIds[$value] = true;
            if ($membership->isActive()) {
                $activeStudentIds[$value] = true;
            }
        }

        $addressIds = self::resourceIdentityMap($addresses, 'FamilyAddress');
        $contactIds = self::resourceIdentityMap($emergencyContacts, 'FamilyEmergencyContact');
        $pickupIds = self::resourceIdentityMap($authorizedPickups, 'FamilyAuthorizedPickup');
        self::assertUniqueAssignmentIds($representativeAddressAssignments, 'RepresentativeAddressAssignment');
        self::assertUniqueAssignmentIds($studentAddressAssignments, 'StudentAddressAssignment');
        self::assertUniqueAssignmentIds($emergencyContactAssignments, 'EmergencyContactAssignment');
        self::assertUniqueAssignmentIds($authorizedPickupAssignments, 'AuthorizedPickupAssignment');

        $activeRepresentativeAddresses = [];
        foreach ($representativeAddressAssignments as $assignment) {
            $resourceId = $assignment->familyAddressId()->value();
            $memberId = $assignment->representativeId()->value();
            self::assertKnownReferences($resourceId, $addressIds, $memberId, $representativeIds, 'Representative address');
            if (!$assignment->isActive()) {
                continue;
            }
            self::assertActiveReferences($resourceId, $addressIds, $memberId, $activeRepresentativeIds, 'Representative address');
            if (isset($activeRepresentativeAddresses[$memberId])) {
                throw new InvalidFamilyState('Representative has multiple active address assignments.');
            }
            $activeRepresentativeAddresses[$memberId] = true;
        }

        $activeStudentAddresses = [];
        foreach ($studentAddressAssignments as $assignment) {
            $resourceId = $assignment->familyAddressId()->value();
            $memberId = $assignment->studentId()->value();
            self::assertKnownReferences($resourceId, $addressIds, $memberId, $studentIds, 'Student address');
            if (!$assignment->isActive()) {
                continue;
            }
            self::assertActiveReferences($resourceId, $addressIds, $memberId, $activeStudentIds, 'Student address');
            if (isset($activeStudentAddresses[$memberId])) {
                throw new InvalidFamilyState('Student has multiple active address assignments.');
            }
            $activeStudentAddresses[$memberId] = true;
        }

        $activeContactStudent = [];
        $activeStudentPriority = [];
        foreach ($emergencyContactAssignments as $assignment) {
            $resourceId = $assignment->familyEmergencyContactId()->value();
            $memberId = $assignment->studentId()->value();
            self::assertKnownReferences($resourceId, $contactIds, $memberId, $studentIds, 'Emergency contact');
            if (!$assignment->isActive()) {
                continue;
            }
            self::assertActiveReferences($resourceId, $contactIds, $memberId, $activeStudentIds, 'Emergency contact');
            $pair = $resourceId . ':' . $memberId;
            if (isset($activeContactStudent[$pair])) {
                throw new InvalidFamilyState('Emergency contact has duplicate active Student assignment.');
            }
            $activeContactStudent[$pair] = true;
            if ($assignment->priority() !== null) {
                $priority = $memberId . ':' . $assignment->priority()->value();
                if (isset($activeStudentPriority[$priority])) {
                    throw new InvalidFamilyState('Emergency contact priority is duplicated for active Student assignments.');
                }
                $activeStudentPriority[$priority] = true;
            }
        }

        $activePickupStudent = [];
        foreach ($authorizedPickupAssignments as $assignment) {
            $resourceId = $assignment->familyAuthorizedPickupId()->value();
            $memberId = $assignment->studentId()->value();
            self::assertKnownReferences($resourceId, $pickupIds, $memberId, $studentIds, 'Authorized pickup');
            if (!$assignment->isActive()) {
                continue;
            }
            self::assertActiveReferences($resourceId, $pickupIds, $memberId, $activeStudentIds, 'Authorized pickup');
            $pair = $resourceId . ':' . $memberId;
            if (isset($activePickupStudent[$pair])) {
                throw new InvalidFamilyState('Authorized pickup has duplicate active Student assignment.');
            }
            $activePickupStudent[$pair] = true;
        }
    }

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     */
    private static function assertMembershipInvariants(array $representatives, array $students): void
    {
        self::assertCollectionType($representatives, FamilyRepresentative::class, 'Family representatives');
        self::assertCollectionType($students, FamilyStudent::class, 'Family students');

        $activeRepresentativeIds = [];
        $activePrimaryCount = 0;
        foreach ($representatives as $membership) {
            if (!$membership->isActive()) {
                continue;
            }

            $representativeId = $membership->representativeId()->value();
            if (isset($activeRepresentativeIds[$representativeId])) {
                throw new InvalidFamilyState('Representative cannot have duplicate active memberships in one Family.');
            }
            $activeRepresentativeIds[$representativeId] = true;
            if ($membership->isPrimary()) {
                $activePrimaryCount++;
            }
        }

        if ($activeRepresentativeIds === []) {
            throw new InvalidFamilyState('Family must have at least one active representative.');
        }
        if ($activePrimaryCount !== 1) {
            throw new InvalidFamilyState('Family must have exactly one active primary representative.');
        }

        $activeStudentIds = [];
        foreach ($students as $membership) {
            if (!$membership->isActive()) {
                continue;
            }
            $studentId = $membership->studentId()->value();
            if (isset($activeStudentIds[$studentId])) {
                throw new InvalidFamilyState('Student cannot have duplicate active memberships in one Family.');
            }
            $activeStudentIds[$studentId] = true;
        }
    }

    /** @param array<int, mixed> $entities */
    private static function assertCollectionType(array $entities, string $class, string $label): void
    {
        foreach ($entities as $entity) {
            if (!$entity instanceof $class) {
                throw new InvalidFamilyState(sprintf('%s contain an invalid Entity type.', $label));
            }
        }
    }

    /**
     * @param array<int, object> $resources
     * @return array<int, bool>
     */
    private static function resourceIdentityMap(array $resources, string $label): array
    {
        $identities = [];
        foreach ($resources as $resource) {
            $id = $resource->id();
            if ($id === null) {
                continue;
            }
            $value = $id->value();
            if (isset($identities[$value])) {
                throw new InvalidFamilyState(sprintf('%s identity is duplicated.', $label));
            }
            $identities[$value] = $resource->isActive();
        }

        return $identities;
    }

    /** @param array<int, object> $assignments */
    private static function assertUniqueAssignmentIds(array $assignments, string $label): void
    {
        $identities = [];
        foreach ($assignments as $assignment) {
            $id = $assignment->id();
            if ($id === null) {
                continue;
            }
            $value = $id->value();
            if (isset($identities[$value])) {
                throw new InvalidFamilyState(sprintf('%s identity is duplicated.', $label));
            }
            $identities[$value] = true;
        }
    }

    /**
     * @param array<int, bool> $resources
     * @param array<int, bool> $members
     */
    private static function assertKnownReferences(
        int $resourceId,
        array $resources,
        int $memberId,
        array $members,
        string $label,
    ): void {
        if (!array_key_exists($resourceId, $resources)) {
            throw new InvalidFamilyState(sprintf('%s assignment references an unknown Family resource.', $label));
        }
        if (!isset($members[$memberId])) {
            throw new InvalidFamilyState(sprintf('%s assignment references an unknown Family member.', $label));
        }
    }

    /**
     * @param array<int, bool> $resources
     * @param array<int, bool> $activeMembers
     */
    private static function assertActiveReferences(
        int $resourceId,
        array $resources,
        int $memberId,
        array $activeMembers,
        string $label,
    ): void {
        if ($resources[$resourceId] !== true) {
            throw new InvalidFamilyState(sprintf('%s assignment references an inactive resource.', $label));
        }
        if (!isset($activeMembers[$memberId])) {
            throw new InvalidFamilyState(sprintf('%s active assignment references an inactive member.', $label));
        }
    }

    /**
     * @template T of object
     * @param list<T> $entities
     * @return list<T>
     */
    private static function cloneEntities(array $entities): array
    {
        return array_map(static fn (object $entity): object => clone $entity, array_values($entities));
    }
}
