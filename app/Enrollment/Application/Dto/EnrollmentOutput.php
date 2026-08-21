<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

use DateTimeImmutable;

final readonly class EnrollmentOutput
{
    public function __construct(
        public int $id,
        public int $studentId,
        public int $familyId,
        public int $academicPeriodId,
        public string $status,
        public ?AcademicPlacementOutput $academicPlacement,
        public ?BillingInformationOutput $billingInformation,
        public ?MedicalInformationOutput $medicalInformation,
        public ?TransportInformationOutput $transportInformation,
        public bool $isAuthorizedToLeaveAlone,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $submittedAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $cancelledAt,
        public bool $hasSubmissionSnapshot,
    ) {
    }
}
