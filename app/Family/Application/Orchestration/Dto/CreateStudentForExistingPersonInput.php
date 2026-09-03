<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use App\Student\Domain\StudentStatus;
use DateTimeImmutable;

final readonly class CreateStudentForExistingPersonInput
{
    public function __construct(
        public int $familyId,
        public int $personId,
        public string $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public StudentStatus $studentStatus,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
