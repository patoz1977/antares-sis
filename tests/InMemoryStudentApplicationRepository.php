<?php

declare(strict_types=1);

namespace Tests;

use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use RuntimeException;

final class InMemoryStudentApplicationRepository implements StudentRepository
{
    /** @var array<int, Student> */
    private array $students = [];

    private int $saveCalls = 0;

    private bool $returnWithoutId = false;

    public function __construct(private int $nextId = 900)
    {
    }

    public function seed(Student $student): void
    {
        $id = $student->id();
        if ($id === null) {
            throw new RuntimeException('Seeded Student must have an identity.');
        }

        $this->students[$id->value()] = clone $student;
        $this->nextId = max($this->nextId, $id->value() + 1);
    }

    public function findById(StudentId $id): ?Student
    {
        return isset($this->students[$id->value()]) ? clone $this->students[$id->value()] : null;
    }

    public function findByPersonId(PersonId $personId): ?Student
    {
        foreach ($this->students as $student) {
            if ($student->personId()->equals($personId)) {
                return clone $student;
            }
        }

        return null;
    }

    public function findByInstitutionalCode(InstitutionalCode $institutionalCode): ?Student
    {
        foreach ($this->students as $student) {
            if ($student->institutionalCode()->equals($institutionalCode)) {
                return clone $student;
            }
        }

        return null;
    }

    public function save(Student $student): Student
    {
        $this->saveCalls++;
        if ($this->returnWithoutId) {
            return new Student(
                null,
                $student->personId(),
                $student->institutionalCode(),
                $student->admissionDate(),
                $student->status(),
            );
        }

        $persisted = $student->id() === null
            ? new Student(
                new StudentId($this->nextId++),
                $student->personId(),
                $student->institutionalCode(),
                $student->admissionDate(),
                $student->status(),
            )
            : clone $student;
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Persisted Student must have an identity.');
        }

        $this->students[$id->value()] = clone $persisted;

        return clone $persisted;
    }

    public function saveCalls(): int
    {
        return $this->saveCalls;
    }

    public function returnWithoutId(): void
    {
        $this->returnWithoutId = true;
    }
}
