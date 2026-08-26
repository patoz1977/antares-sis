<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;

final readonly class StudentMedicalReportRow
{
    public function __construct(
        public int $studentId,
        public ?int $gradeId,
        public ?string $gradeName,
        public ?int $sectionId,
        public ?string $sectionName,
        public string $studentSurnames,
        public string $studentNames,
        public EnrollmentReportingStatus $status,
        public ?bool $hasMedicalCondition,
        public ?string $medicalConditionDetail,
        public ?bool $hasAllergies,
        public ?string $allergyDetail,
        public ?bool $takesPermanentMedication,
        public ?string $medicationName,
        public ?bool $requiresSpecialCare,
        public ?string $specialCareDetail,
        public ?bool $hasMedicalInsurance,
        public ?string $insuranceProvider,
        public ?string $pediatricianName,
        public ?string $pediatricianPhone,
        public ?string $observations,
    ) {
    }
}
