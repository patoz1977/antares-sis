<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Exception\EnrollmentNotFound;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\EnrollmentId;

final readonly class GetEnrollment
{
    public function __construct(private EnrollmentRepository $enrollments)
    {
    }

    public function handle(int $enrollmentId): EnrollmentOutput
    {
        $enrollment = $this->enrollments->findById(new EnrollmentId($enrollmentId));
        if ($enrollment === null) {
            throw new EnrollmentNotFound('Enrollment was not found.');
        }

        return EnrollmentApplicationSupport::output($enrollment);
    }
}
