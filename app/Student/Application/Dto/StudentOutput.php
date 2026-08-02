<?php

declare(strict_types=1);

namespace App\Student\Application\Dto;

use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final readonly class StudentOutput
{
    public function __construct(
        public int $id,
        public int $personId,
        public string $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public StudentStatus $status,
    ) {
    }

    public static function fromStudent(Student $student, StudentId $id): self
    {
        return new self(
            $id->value(),
            $student->personId()->value(),
            $student->institutionalCode()->value(),
            $student->admissionDate()->value(),
            $student->status(),
        );
    }
}
