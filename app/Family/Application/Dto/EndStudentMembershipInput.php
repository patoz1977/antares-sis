<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use DateTimeImmutable;

final readonly class EndStudentMembershipInput
{
    public function __construct(
        public int $familyId,
        public int $studentId,
        public DateTimeImmutable $endedAt,
    ) {
    }
}
