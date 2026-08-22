<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Support;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentUnavailable;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentPortalAuthorization;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;

final readonly class RepresentativeEnrollmentMutationSupport
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    /** @param callable(Enrollment): void $mutation */
    public function update(
        int $expectedFamilyId,
        int $expectedAcademicPeriodId,
        int $studentId,
        callable $mutation,
    ): EnrollmentOutput {
        return $this->transactions->run(function () use (
            $expectedFamilyId,
            $expectedAcademicPeriodId,
            $studentId,
            $mutation,
        ): EnrollmentOutput {
            $context = $this->authorization->resolveMutationContext(
                $expectedFamilyId,
                $expectedAcademicPeriodId,
                $studentId,
            );
            $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
                new StudentId($studentId),
                new AcademicPeriodId($context->academicPeriodId),
            );
            $enrollmentId = $enrollment?->id();
            if ($enrollment === null || $enrollmentId === null) {
                throw new RepresentativeEnrollmentUnavailable(
                    'Current Representative Enrollment is unavailable.'
                );
            }

            return EnrollmentApplicationSupport::updateInsideCurrentTransaction(
                $this->enrollments,
                new RepresentativeEnrollmentMutationInput(
                    $enrollmentId->value(),
                    $studentId,
                    $context->familyId,
                    $context->academicPeriodId,
                ),
                $mutation,
            );
        });
    }
}
