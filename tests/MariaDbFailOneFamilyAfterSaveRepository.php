<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyCode;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use RuntimeException;

final readonly class MariaDbFailOneFamilyAfterSaveRepository implements FamilyRepository
{
    public function __construct(
        private FamilyRepository $delegate,
        private string $familyCode,
    ) {
    }

    public function findById(FamilyId $id): ?Family
    {
        return $this->delegate->findById($id);
    }

    public function findByIdForUpdate(FamilyId $id): ?Family
    {
        return $this->delegate->findByIdForUpdate($id);
    }

    public function findByCode(FamilyCode $familyCode): ?Family
    {
        return $this->delegate->findByCode($familyCode);
    }

    public function findByCodeForUpdate(FamilyCode $familyCode): ?Family
    {
        return $this->delegate->findByCodeForUpdate($familyCode);
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
        return $this->delegate->findActiveByRepresentativeAndFamilyForUpdate(
            $representativeId,
            $familyId,
        );
    }

    public function save(Family $family): Family
    {
        $persisted = $this->delegate->save($family);
        if ($family->familyCode()->value() === $this->familyCode) {
            throw new RuntimeException('Synthetic Family failure after physical save.');
        }

        return $persisted;
    }
}
