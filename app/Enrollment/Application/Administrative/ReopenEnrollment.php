<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative;

use App\Enrollment\Application\Administrative\Support\AdministrativeEnrollmentLifecycle;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Domain\EnrollmentRepository;
use Core\Application\TransactionRunner;

final readonly class ReopenEnrollment
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(int $enrollmentId): EnrollmentOutput
    {
        return AdministrativeEnrollmentLifecycle::execute(
            $enrollmentId,
            $this->enrollments,
            $this->transactions,
            static fn ($enrollment) => $enrollment->reopen(),
        );
    }
}
