<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative;

use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentUnavailable;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\EnrollmentId;

final readonly class GetAdministrativeEnrollmentReview
{
    public function __construct(private EnrollmentRepository $enrollments)
    {
    }

    public function handle(int $enrollmentId): EnrollmentOutput
    {
        if ($enrollmentId <= 0) {
            throw new AdministrativeEnrollmentUnavailable(
                'Administrative Enrollment target is unavailable.'
            );
        }

        $enrollment = $this->enrollments->findById(new EnrollmentId($enrollmentId));
        if ($enrollment === null) {
            throw new AdministrativeEnrollmentUnavailable(
                'Administrative Enrollment target is unavailable.'
            );
        }

        return EnrollmentApplicationSupport::output($enrollment);
    }
}
