<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\Family\Domain\Family;

final readonly class RepresentativeEnrollmentMutationContext
{
    public function __construct(
        public int $representativePersonId,
        public int $representativeId,
        public int $familyId,
        public int $academicPeriodId,
        public ?int $studentId = null,
        public ?int $studentPersonId = null,
        public ?Family $lockedStudentFamily = null,
    ) {
    }
}
