<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\StudentId;

final readonly class E012TracingEnrollmentRepository implements EnrollmentRepository
{
    public function __construct(
        private EnrollmentRepository $delegate,
        private E012SubmissionTrace $trace,
    ) {
    }

    public function findById(EnrollmentId $id): ?Enrollment
    {
        return $this->delegate->findById($id);
    }

    public function findByIdForUpdate(EnrollmentId $id): ?Enrollment
    {
        $this->trace->events[] = 'enrollment-lock';

        return $this->delegate->findByIdForUpdate($id);
    }

    public function findByStudentAndAcademicPeriod(
        StudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment {
        return $this->delegate->findByStudentAndAcademicPeriod($studentId, $academicPeriodId);
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        return $this->delegate->save($enrollment);
    }
}
