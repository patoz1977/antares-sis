<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class MedicalInformationOutput
{
    public function __construct(
        public bool $hasMedicalCondition,
        public ?string $medicalConditionDetail,
        public bool $hasAllergies,
        public ?string $allergyDetail,
        public bool $takesPermanentMedication,
        public ?string $medicationName,
        public bool $requiresSpecialCare,
        public ?string $specialCareDetail,
        public bool $hasMedicalInsurance,
        public ?string $insuranceProvider,
        public ?string $pediatricianName,
        public ?string $pediatricianPhone,
        public ?string $observations,
    ) {
    }
}
