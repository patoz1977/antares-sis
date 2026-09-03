<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use DateTimeImmutable;

final readonly class AttachExistingStudentToFamilyInput
{
    public function __construct(
        public int $familyId,
        public int $studentId,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
