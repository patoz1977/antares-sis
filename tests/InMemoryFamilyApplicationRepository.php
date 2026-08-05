<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RepresentativeId;
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

    public function __construct(
        private int $nextFamilyId = 500,
        private int $nextRepresentativeMembershipId = 800,
        private int $nextStudentMembershipId = 1200,
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
        $persisted = Family::reconstitute(
            $familyId,
            $family->displayName(),
            $family->status(),
            $representatives,
            $students,
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
        );
    }
}
