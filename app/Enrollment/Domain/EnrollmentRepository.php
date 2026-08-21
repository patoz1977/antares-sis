<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\StudentId;

interface EnrollmentRepository
{
    public function findById(EnrollmentId $id): ?Enrollment;

    public function findByStudentAndAcademicPeriod(
        StudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment;

    public function save(Enrollment $enrollment): Enrollment;
}
