<?php

declare(strict_types=1);

namespace App\Student\Application\Dto;

use App\Student\Domain\StudentStatus;
use DateTimeImmutable;

final readonly class CreateStudentInput
{
    public function __construct(
        public int $personId,
        public string $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public StudentStatus $status,
    ) {
    }
}
