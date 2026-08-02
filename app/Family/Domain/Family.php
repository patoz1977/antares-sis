<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
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

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     */
    private function __construct(
        private readonly ?FamilyId $id,
        private DisplayName $displayName,
        private FamilyStatus $status,
        array $representatives,
        array $students,
    ) {
        self::assertMembershipInvariants($representatives, $students);
        $this->representatives = self::cloneRepresentatives($representatives);
        $this->students = self::cloneStudents($students);
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

        return new self(null, $displayName, $status, [$initialRepresentative], []);
    }

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     */
    public static function reconstitute(
        FamilyId $id,
        DisplayName $displayName,
        FamilyStatus $status,
        array $representatives,
        array $students,
    ): self {
        return new self($id, $displayName, $status, $representatives, $students);
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
        return self::cloneRepresentatives($this->representatives);
    }

    /** @return list<FamilyStudent> */
    public function students(): array
    {
        return self::cloneStudents($this->students);
    }

    /** @return list<FamilyRepresentative> */
    public function activeRepresentatives(): array
    {
        return self::cloneRepresentatives(array_values(array_filter(
            $this->representatives,
            static fn (FamilyRepresentative $membership): bool => $membership->isActive(),
        )));
    }

    /** @return list<FamilyStudent> */
    public function activeStudents(): array
    {
        return self::cloneStudents(array_values(array_filter(
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
        $membership = new FamilyRepresentative(
            null,
            $representativeId,
            $relationshipTypeId,
            false,
            $startedAt,
            null,
        );

        if ($this->activeRepresentativeIndex($representativeId) !== null) {
            throw new InvalidFamilyState('Representative already has an active Family membership.');
        }

        $this->representatives[] = $membership;

        return clone $membership;
    }

    public function addStudent(StudentId $studentId, DateTimeImmutable $startedAt): FamilyStudent
    {
        $membership = new FamilyStudent(null, $studentId, $startedAt, null);

        if ($this->activeStudentIndex($studentId) !== null) {
            throw new InvalidFamilyState('Student already has an active membership in this Family.');
        }

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

        $membership->end($endedAt);
    }

    public function endStudentMembership(StudentId $studentId, DateTimeImmutable $endedAt): void
    {
        $index = $this->activeStudentIndex($studentId);
        if ($index === null) {
            throw new InvalidFamilyState('Active FamilyStudent membership was not found.');
        }

        $this->students[$index]->end($endedAt);
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

    /**
     * @param list<FamilyRepresentative> $representatives
     * @param list<FamilyStudent> $students
     */
    private static function assertMembershipInvariants(array $representatives, array $students): void
    {
        $activeRepresentativeIds = [];
        $activePrimaryCount = 0;

        foreach ($representatives as $membership) {
            if (!$membership instanceof FamilyRepresentative) {
                throw new InvalidFamilyState('Family representatives must be FamilyRepresentative entities.');
            }

            if (!$membership->isActive()) {
                continue;
            }

            $representativeId = $membership->representativeId()->value();
            if (isset($activeRepresentativeIds[$representativeId])) {
                throw new InvalidFamilyState(
                    'Representative cannot have duplicate active memberships in one Family.'
                );
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
            if (!$membership instanceof FamilyStudent) {
                throw new InvalidFamilyState('Family students must be FamilyStudent entities.');
            }

            if (!$membership->isActive()) {
                continue;
            }

            $studentId = $membership->studentId()->value();
            if (isset($activeStudentIds[$studentId])) {
                throw new InvalidFamilyState(
                    'Student cannot have duplicate active memberships in one Family.'
                );
            }

            $activeStudentIds[$studentId] = true;
        }
    }

    /**
     * @param list<FamilyRepresentative> $representatives
     * @return list<FamilyRepresentative>
     */
    private static function cloneRepresentatives(array $representatives): array
    {
        return array_map(
            static fn (FamilyRepresentative $membership): FamilyRepresentative => clone $membership,
            array_values($representatives),
        );
    }

    /**
     * @param list<FamilyStudent> $students
     * @return list<FamilyStudent>
     */
    private static function cloneStudents(array $students): array
    {
        return array_map(
            static fn (FamilyStudent $membership): FamilyStudent => clone $membership,
            array_values($students),
        );
    }
}
