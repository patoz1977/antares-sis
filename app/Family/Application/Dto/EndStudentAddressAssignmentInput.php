<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use DateTimeImmutable;

final readonly class EndStudentAddressAssignmentInput
{
    public function __construct(
        public int $familyId,
        public int $studentId,
        public DateTimeImmutable $endedAt,
    ) {
    }
}
