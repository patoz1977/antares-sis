<?php

declare(strict_types=1);

namespace App\Student\Application\Dto;

use App\Student\Domain\StudentStatus;
use DateTimeImmutable;

final readonly class UpdateStudentInput
{
    public function __construct(
        public int $studentId,
        public string $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public StudentStatus $status,
    ) {
    }
}
