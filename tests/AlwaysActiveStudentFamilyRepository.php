<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class AlwaysActiveStudentFamilyRepository implements FamilyRepository
{
    public function __construct(
        private FamilyRepository $delegate,
        private int $activeFamilyId,
    ) {
    }

    public function findById(FamilyId $id): ?Family
    {
        return $this->delegate->findById($id);
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        return $this->delegate->findActiveByRepresentativeId($representativeId);
    }

    public function findActiveByStudentId(StudentId $studentId): ?Family
    {
        return $this->delegate->findById(new FamilyId($this->activeFamilyId));
    }

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
    {
        return $this->findActiveByStudentId($studentId);
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        return $this->delegate->findById($familyId);
    }

    public function save(Family $family): Family
    {
        return $this->delegate->save($family);
    }
}
