<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentTransportInput;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;
use App\Enrollment\Domain\ValueObject\TransportInformation;

final readonly class UpdateRepresentativeEnrollmentTransportInformation
{
    public function __construct(private RepresentativeEnrollmentMutationSupport $mutations)
    {
    }

    public function handle(UpdateRepresentativeEnrollmentTransportInput $input): EnrollmentOutput
    {
        return $this->mutations->update(
            $input->expectedFamilyId,
            $input->expectedAcademicPeriodId,
            $input->studentId,
            static fn ($enrollment) => $enrollment->updateTransportInformation(
                new TransportInformation($input->requiresInstitutionalTransport),
            ),
        );
    }
}
