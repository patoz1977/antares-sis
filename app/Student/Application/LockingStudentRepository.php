<?php

declare(strict_types=1);

namespace App\Student\Application;

use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;

interface LockingStudentRepository extends StudentRepository
{
    public function findByIdForUpdate(StudentId $id): ?Student;

    public function findByPersonIdForUpdate(PersonId $personId): ?Student;

    public function findByInstitutionalCodeForUpdate(InstitutionalCode $institutionalCode): ?Student;
}
