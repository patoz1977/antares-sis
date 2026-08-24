<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative\Support;

use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentInvalidTransition;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentPersistedStateMismatch;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentUnavailable;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Exception\EnrollmentPersistedStateMismatch;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use Core\Application\TransactionRunner;

final class AdministrativeEnrollmentLifecycle
{
    /** @param callable(Enrollment): void $transition */
    public static function execute(
        int $enrollmentId,
        EnrollmentRepository $enrollments,
        TransactionRunner $transactions,
        callable $transition,
    ): EnrollmentOutput {
        return $transactions->run(function () use (
            $enrollmentId,
            $enrollments,
            $transition,
        ): EnrollmentOutput {
            if ($enrollmentId <= 0) {
                throw new AdministrativeEnrollmentUnavailable(
                    'Administrative Enrollment target is unavailable.'
                );
            }

            $id = new EnrollmentId($enrollmentId);
            $enrollment = $enrollments->findByIdForUpdate($id);
            if ($enrollment === null || $enrollment->id()?->equals($id) !== true) {
                throw new AdministrativeEnrollmentUnavailable(
                    'Administrative Enrollment target is unavailable.'
                );
            }

            try {
                $transition($enrollment);
            } catch (InvalidEnrollmentState $exception) {
                throw new AdministrativeEnrollmentInvalidTransition(
                    'Administrative Enrollment lifecycle transition is invalid.',
                    previous: $exception,
                );
            }

            $expected = self::output($enrollment);
            try {
                $saved = self::output($enrollments->save($enrollment));
            } catch (EnrollmentPersistedStateMismatch $exception) {
                throw new AdministrativeEnrollmentPersistedStateMismatch(
                    'Administrative Enrollment persistence returned incoherent state.',
                    previous: $exception,
                );
            }
            self::assertSameState($saved, $expected);

            $reloaded = $enrollments->findById($id);
            if ($reloaded === null) {
                throw new AdministrativeEnrollmentPersistedStateMismatch(
                    'Administrative Enrollment could not be reloaded after persistence.'
                );
            }
            $result = self::output($reloaded);
            self::assertSameState($result, $expected);

            return $result;
        });
    }

    private static function output(Enrollment $enrollment): EnrollmentOutput
    {
        try {
            return EnrollmentApplicationSupport::output($enrollment);
        } catch (EnrollmentPersistedStateMismatch $exception) {
            throw new AdministrativeEnrollmentPersistedStateMismatch(
                'Administrative Enrollment persistence returned incoherent state.',
                previous: $exception,
            );
        }
    }

    private static function assertSameState(
        EnrollmentOutput $actual,
        EnrollmentOutput $expected,
    ): void {
        if ($actual->id !== $expected->id
            || $actual->studentId !== $expected->studentId
            || $actual->familyId !== $expected->familyId
            || $actual->academicPeriodId !== $expected->academicPeriodId
            || $actual->status !== $expected->status
            || $actual->startedAt != $expected->startedAt
            || $actual->submittedAt != $expected->submittedAt
            || $actual->completedAt != $expected->completedAt
            || $actual->cancelledAt != $expected->cancelledAt
            || $actual->academicPlacement != $expected->academicPlacement
            || $actual->billingInformation != $expected->billingInformation
            || $actual->medicalInformation != $expected->medicalInformation
            || $actual->transportInformation != $expected->transportInformation
            || $actual->isAuthorizedToLeaveAlone !== $expected->isAuthorizedToLeaveAlone
        ) {
            throw new AdministrativeEnrollmentPersistedStateMismatch(
                'Administrative Enrollment persistence returned incoherent state.'
            );
        }
    }

    private function __construct()
    {
    }
}
