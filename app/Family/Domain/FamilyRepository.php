<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;

interface FamilyRepository
{
    public function findById(FamilyId $id): ?Family;

    /** @return list<Family> */
    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array;

    public function findActiveByStudentId(StudentId $studentId): ?Family;

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family;

    public function save(Family $family): Family;
}
