<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Dto;

final readonly class EnrollmentSubmissionEvaluationContext
{
    public function __construct(
        public bool $enrollmentIsDraft,
        public bool $representativeHasPersonalEmail,
        public bool $representativeHasMobilePhone,
        public int $activeStudentAddressCount,
        public bool $hasBillingInformation,
        public bool $hasMedicalInformation,
        public bool $hasTransportInformation,
        public int $activeEmergencyContactCount,
        public int $activeAuthorizedPickupCount,
        public bool $isAuthorizedToLeaveAlone,
        public bool $acknowledgementsSatisfied,
        public bool $hasAcademicPlacement,
    ) {
    }
}
