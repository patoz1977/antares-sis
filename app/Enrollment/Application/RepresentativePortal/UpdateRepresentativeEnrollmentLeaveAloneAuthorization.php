<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentLeaveAloneInput;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;

final readonly class UpdateRepresentativeEnrollmentLeaveAloneAuthorization
{
    public function __construct(private RepresentativeEnrollmentMutationSupport $mutations)
    {
    }

    public function handle(UpdateRepresentativeEnrollmentLeaveAloneInput $input): EnrollmentOutput
    {
        return $this->mutations->update(
            $input->expectedFamilyId,
            $input->expectedAcademicPeriodId,
            $input->studentId,
            static fn ($enrollment) => $enrollment->updateLeaveAloneAuthorization(
                $input->isAuthorizedToLeaveAlone,
            ),
        );
    }
}
