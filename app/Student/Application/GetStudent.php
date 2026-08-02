<?php

declare(strict_types=1);

namespace App\Student\Application;

use App\Student\Application\Dto\StudentOutput;
use App\Student\Application\Exception\StudentNotFound;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\StudentId;

final readonly class GetStudent
{
    public function __construct(private StudentRepository $students)
    {
    }

    public function handle(int $studentId): StudentOutput
    {
        $id = new StudentId($studentId);
        $student = $this->students->findById($id);
        if ($student === null) {
            throw new StudentNotFound('Student was not found.');
        }

        return StudentOutput::fromStudent($student, $id);
    }
}
