<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\StudentId;
use RuntimeException;
use Throwable;

final class E012AdministrativeEnrollmentRepository implements EnrollmentRepository
{
    /** @var array<int, Enrollment> */
    private array $enrollments = [];
    public int $lockCalls = 0;
    public int $saveCalls = 0;
    public bool $returnIncoherentState = false;
    public ?Throwable $saveFailure = null;
    /** @var list<string> */
    public array $trace = [];

    /** @param list<Enrollment> $enrollments */
    public function __construct(
        private readonly E012AdministrativeTransactionRunner $transactions,
        array $enrollments,
    ) {
        foreach ($enrollments as $enrollment) {
            $id = $enrollment->id()?->value() ?? throw new RuntimeException('Fixture Enrollment requires identity.');
            $this->enrollments[$id] = clone $enrollment;
        }
    }

    public function findById(EnrollmentId $id): ?Enrollment
    {
        if ($this->transactions->active) {
            $this->trace[] = 'read';
        }

        return isset($this->enrollments[$id->value()])
            ? clone $this->enrollments[$id->value()]
            : null;
    }

    public function findByIdForUpdate(EnrollmentId $id): ?Enrollment
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Administrative lock must occur inside transaction.');
        }
        $this->lockCalls++;
        $this->trace[] = 'lock';

        return isset($this->enrollments[$id->value()])
            ? clone $this->enrollments[$id->value()]
            : null;
    }

    public function findByStudentAndAcademicPeriod(
        StudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment {
        foreach ($this->enrollments as $enrollment) {
            if ($enrollment->studentId()->equals($studentId)
                && $enrollment->academicPeriodId()->equals($academicPeriodId)
            ) {
                return clone $enrollment;
            }
        }

        return null;
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Administrative save must occur inside transaction.');
        }
        $this->saveCalls++;
        $this->trace[] = 'save';
        if ($this->saveFailure !== null) {
            throw $this->saveFailure;
        }
        $id = $enrollment->id()?->value() ?? throw new RuntimeException('Enrollment identity is required.');
        $this->enrollments[$id] = clone $enrollment;

        return $this->returnIncoherentState
            ? e012AdministrativeEnrollment($enrollment->status(), familyId: 802)
            : clone $enrollment;
    }

    /** @return array<int, Enrollment> */
    public function snapshot(): array
    {
        return array_map(static fn (Enrollment $enrollment): Enrollment => clone $enrollment, $this->enrollments);
    }

    /** @param array<int, Enrollment> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->enrollments = array_map(
            static fn (Enrollment $enrollment): Enrollment => clone $enrollment,
            $snapshot,
        );
    }
}
