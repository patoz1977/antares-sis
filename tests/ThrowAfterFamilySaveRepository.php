<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use Throwable;

final readonly class ThrowAfterFamilySaveRepository implements FamilyRepository
{
    public function __construct(
        private FamilyRepository $delegate,
        private Throwable $failure,
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
        return $this->delegate->findActiveByStudentId($studentId);
    }

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
    {
        return $this->delegate->findActiveByStudentIdForUpdate($studentId);
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        return $this->delegate->findActiveByRepresentativeAndFamilyForUpdate($representativeId, $familyId);
    }

    public function save(Family $family): Family
    {
        $this->delegate->save($family);
        throw $this->failure;
    }
}
