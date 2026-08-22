<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentMedicalInput;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;
use App\Enrollment\Domain\ValueObject\MedicalInformation;

final readonly class UpdateRepresentativeEnrollmentMedicalInformation
{
    public function __construct(private RepresentativeEnrollmentMutationSupport $mutations)
    {
    }

    public function handle(UpdateRepresentativeEnrollmentMedicalInput $input): EnrollmentOutput
    {
        return $this->mutations->update(
            $input->expectedFamilyId,
            $input->expectedAcademicPeriodId,
            $input->studentId,
            static fn ($enrollment) => $enrollment->updateMedicalInformation(new MedicalInformation(
                $input->hasMedicalCondition,
                $input->medicalConditionDetail,
                $input->hasAllergies,
                $input->allergyDetail,
                $input->takesPermanentMedication,
                $input->medicationName,
                $input->requiresSpecialCare,
                $input->specialCareDetail,
                $input->hasMedicalInsurance,
                $input->insuranceProvider,
                $input->pediatricianName,
                $input->pediatricianPhone,
                $input->observations,
            )),
        );
    }
}
