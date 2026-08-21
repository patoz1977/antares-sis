<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;

final readonly class GetEnrollmentByStudentAndAcademicPeriod
{
    public function __construct(private EnrollmentRepository $enrollments)
    {
    }

    public function handle(int $studentId, int $academicPeriodId): ?EnrollmentOutput
    {
        $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
            new StudentId($studentId),
            new AcademicPeriodId($academicPeriodId),
        );

        return $enrollment === null ? null : EnrollmentApplicationSupport::output($enrollment);
    }
}
