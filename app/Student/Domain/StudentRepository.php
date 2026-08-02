<?php

declare(strict_types=1);

namespace App\Student\Domain;

use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;

interface StudentRepository
{
    public function findById(StudentId $id): ?Student;

    public function findByPersonId(PersonId $personId): ?Student;

    public function findByInstitutionalCode(InstitutionalCode $institutionalCode): ?Student;

    public function save(Student $student): Student;
}
