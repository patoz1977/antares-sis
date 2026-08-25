<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative\Dto;

use DateTimeImmutable;

final readonly class SubmittedEnrollmentListItem
{
    public function __construct(
        public int $enrollmentId,
        public string $studentDisplayName,
        public string $familyDisplayName,
        public string $academicPeriodDisplayName,
        public ?string $gradeDisplayName,
        public DateTimeImmutable $submittedAt,
    ) {
    }
}
