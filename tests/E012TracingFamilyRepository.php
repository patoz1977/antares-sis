<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class E012TracingFamilyRepository implements FamilyRepository
{
    public function __construct(
        private FamilyRepository $delegate,
        private E012SubmissionTrace $trace,
    ) {
    }

    public function findById(FamilyId $id): ?Family
    {
        return $this->delegate->findById($id);
    }

    public function findByIdForUpdate(FamilyId $id): ?Family
    {
        $this->trace->events[] = 'family-root-lock';

        return $this->delegate->findByIdForUpdate($id);
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        return $this->delegate->findActiveByRepresentativeId($representativeId);
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        $this->trace->events[] = 'representative-membership-lock';

        return $this->delegate->findActiveByRepresentativeAndFamilyForUpdate($representativeId, $familyId);
    }

    public function findActiveByStudentId(StudentId $studentId): ?Family
    {
        return $this->delegate->findActiveByStudentId($studentId);
    }

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
    {
        $this->trace->events[] = 'student-membership-lock';

        return $this->delegate->findActiveByStudentIdForUpdate($studentId);
    }

    public function save(Family $family): Family
    {
        return $this->delegate->save($family);
    }
}
