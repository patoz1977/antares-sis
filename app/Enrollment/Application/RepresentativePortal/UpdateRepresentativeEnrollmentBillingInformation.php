<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentBillingInput;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;

final readonly class UpdateRepresentativeEnrollmentBillingInformation
{
    public function __construct(private RepresentativeEnrollmentMutationSupport $mutations)
    {
    }

    public function handle(UpdateRepresentativeEnrollmentBillingInput $input): EnrollmentOutput
    {
        return $this->mutations->update(
            $input->expectedFamilyId,
            $input->expectedAcademicPeriodId,
            $input->studentId,
            static fn ($enrollment) => $enrollment->updateBillingInformation(new BillingInformation(
                new IdentificationTypeId($input->identificationTypeId),
                $input->identificationNumber,
                $input->legalName,
                $input->billingAddress,
                $input->billingEmail,
                $input->phone,
            )),
        );
    }
}
