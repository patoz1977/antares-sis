<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use DateTimeImmutable;

final readonly class EndEmergencyContactAssignmentInput
{
    public function __construct(
        public int $familyId,
        public int $familyEmergencyContactId,
        public int $studentId,
        public DateTimeImmutable $endedAt,
    ) {
    }
}
