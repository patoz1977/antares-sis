<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\UpdateEnrollmentMedicalInformationInput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use Core\Application\TransactionRunner;

final readonly class UpdateEnrollmentMedicalInformation
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateEnrollmentMedicalInformationInput $input): EnrollmentOutput
    {
        return EnrollmentApplicationSupport::update(
            $this->enrollments,
            $this->transactions,
            $input,
            static function (Enrollment $enrollment) use ($input): void {
                $enrollment->updateMedicalInformation(new MedicalInformation(
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
                ));
            },
        );
    }
}
