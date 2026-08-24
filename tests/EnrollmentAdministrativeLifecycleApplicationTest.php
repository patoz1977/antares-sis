<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Application\Administrative\CancelEnrollment;
use App\Enrollment\Application\Administrative\CompleteEnrollment;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentInvalidTransition;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentPersistedStateMismatch;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentUnavailable;
use App\Enrollment\Application\Administrative\GetAdministrativeEnrollmentReview;
use App\Enrollment\Application\Administrative\ReopenEnrollment;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentAdministrativeLifecycleApplicationTests(TestRunner $runner): void
{
    $runner->add(
        'E012 administrative review returns current annual lifecycle state without side effects',
        function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );

        $review = (new GetAdministrativeEnrollmentReview($repository))->handle(701);

        assertSameValue('SUBMITTED', $review->status);
        assertSameValue('2026-08-24 10:01:02', $review->submittedAt?->format('Y-m-d H:i:s'));
        assertSameValue('Annual legal name', $review->billingInformation?->legalName);
        assertSameValue('Annual observations', $review->medicalInformation?->observations);
        assertSameValue(true, $review->transportInformation?->requiresInstitutionalTransport);
        assertSameValue(0, $transactions->calls);
        assertSameValue(0, $repository->lockCalls);
        assertSameValue(0, $repository->saveCalls);
        assertThrows(
            static fn () => (new GetAdministrativeEnrollmentReview($repository))->handle(999),
            AdministrativeEnrollmentUnavailable::class,
        );
        },
    );

    $runner->add(
        'E012 administrative Reopen preserves identity annual state and submission history',
        function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );
        $before = (new GetAdministrativeEnrollmentReview($repository))->handle(701);
        $repository->trace = [];

        $result = (new ReopenEnrollment($repository, $transactions))->handle(701);

        assertSameValue('DRAFT', $result->status);
        assertSameValue(
            $before->submittedAt?->format('Y-m-d H:i:s'),
            $result->submittedAt?->format('Y-m-d H:i:s'),
        );
        assertSameValue(null, $result->completedAt);
        assertSameValue(null, $result->cancelledAt);
        e012AssertAdministrativePreserved($before, $result);
        assertSameValue(1, $transactions->calls);
        assertSameValue(['begin', 'lock', 'save', 'read', 'commit'], $repository->trace);
        },
    );

    $runner->add('E012 administrative Reopen rejects every non-Submitted state and rolls back', function (): void {
        foreach ([EnrollmentStatus::Draft, EnrollmentStatus::Completed, EnrollmentStatus::Cancelled] as $status) {
            [$repository, $transactions] = e012AdministrativeFixture(e012AdministrativeEnrollment($status));

            assertThrows(
                static fn () => (new ReopenEnrollment($repository, $transactions))->handle(701),
                AdministrativeEnrollmentInvalidTransition::class,
            );
            assertSameValue(1, $transactions->calls);
            assertSameValue(1, $transactions->rollbacks);
            assertSameValue(0, $repository->saveCalls);
            assertSameValue($status->value, (new GetAdministrativeEnrollmentReview($repository))->handle(701)->status);
        }
    });

    $runner->add('E012 administrative Complete uses Clock and preserves Submitted annual state', function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );
        $before = (new GetAdministrativeEnrollmentReview($repository))->handle(701);
        $clock = new E012AdministrativeClock('2026-08-24 11:12:13+00:00');

        $result = (new CompleteEnrollment($repository, $transactions, $clock))->handle(701);

        assertSameValue('COMPLETED', $result->status);
        assertSameValue('2026-08-24 11:12:13', $result->completedAt?->format('Y-m-d H:i:s'));
        assertSameValue(
            $before->submittedAt?->format('Y-m-d H:i:s'),
            $result->submittedAt?->format('Y-m-d H:i:s'),
        );
        assertSameValue(null, $result->cancelledAt);
        assertSameValue(1, $clock->calls);
        e012AssertAdministrativePreserved($before, $result);
    });

    $runner->add('E012 administrative Complete rejects Draft Completed and Cancelled', function (): void {
        foreach ([EnrollmentStatus::Draft, EnrollmentStatus::Completed, EnrollmentStatus::Cancelled] as $status) {
            [$repository, $transactions] = e012AdministrativeFixture(e012AdministrativeEnrollment($status));
            $clock = new E012AdministrativeClock('2026-08-24 11:12:13+00:00');

            assertThrows(
                static fn () => (new CompleteEnrollment($repository, $transactions, $clock))->handle(701),
                AdministrativeEnrollmentInvalidTransition::class,
            );
            assertSameValue(1, $transactions->rollbacks);
            assertSameValue(0, $repository->saveCalls);
            assertSameValue($status->value, (new GetAdministrativeEnrollmentReview($repository))->handle(701)->status);
        }
    });

    $runner->add('E012 administrative Cancel preserves exact Draft Submitted and reopened history', function (): void {
        $cases = [
            [e012AdministrativeEnrollment(EnrollmentStatus::Draft), null],
            [e012AdministrativeEnrollment(EnrollmentStatus::Submitted), '2026-08-24 10:01:02'],
            [e012AdministrativeEnrollment(EnrollmentStatus::Draft, submittedHistory: true), '2026-08-24 10:01:02'],
        ];

        foreach ($cases as [$enrollment, $submittedAt]) {
            [$repository, $transactions] = e012AdministrativeFixture($enrollment);
            $before = (new GetAdministrativeEnrollmentReview($repository))->handle(701);
            $clock = new E012AdministrativeClock('2026-08-24 12:13:14+00:00');

            $result = (new CancelEnrollment($repository, $transactions, $clock))->handle(701);

            assertSameValue('CANCELLED', $result->status);
            assertSameValue($submittedAt, $result->submittedAt?->format('Y-m-d H:i:s'));
            assertSameValue('2026-08-24 12:13:14', $result->cancelledAt?->format('Y-m-d H:i:s'));
            assertSameValue(null, $result->completedAt);
            e012AssertAdministrativePreserved($before, $result);
        }
    });

    $runner->add('E012 administrative Cancel rejects Completed and Cancelled', function (): void {
        foreach ([EnrollmentStatus::Completed, EnrollmentStatus::Cancelled] as $status) {
            [$repository, $transactions] = e012AdministrativeFixture(e012AdministrativeEnrollment($status));

            assertThrows(
                static fn () => (new CancelEnrollment(
                    $repository,
                    $transactions,
                    new E012AdministrativeClock('2026-08-24 12:13:14+00:00'),
                ))->handle(701),
                AdministrativeEnrollmentInvalidTransition::class,
            );
            assertSameValue(1, $transactions->rollbacks);
            assertSameValue(0, $repository->saveCalls);
        }
    });

    $runner->add('E012 administrative lifecycle fails closed for invalid and unavailable targets', function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );
        $service = new ReopenEnrollment($repository, $transactions);

        assertThrows(static fn () => $service->handle(0), AdministrativeEnrollmentUnavailable::class);
        assertThrows(static fn () => $service->handle(999), AdministrativeEnrollmentUnavailable::class);
        assertSameValue(2, $transactions->calls);
        assertSameValue(2, $transactions->rollbacks);
        assertSameValue(0, $repository->saveCalls);
    });

    $runner->add(
        'E012 administrative persisted mismatch rolls back without partial lifecycle state',
        function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );
        $repository->returnIncoherentState = true;

        assertThrows(
            static fn () => (new CompleteEnrollment(
                $repository,
                $transactions,
                new E012AdministrativeClock('2026-08-24 11:12:13+00:00'),
            ))->handle(701),
            AdministrativeEnrollmentPersistedStateMismatch::class,
        );

        assertSameValue(1, $transactions->rollbacks);
        assertSameValue('SUBMITTED', (new GetAdministrativeEnrollmentReview($repository))->handle(701)->status);
        assertSameValue(null, (new GetAdministrativeEnrollmentReview($repository))->handle(701)->completedAt);
        },
    );

    $runner->add(
        'E012 administrative persistence exception rolls back and releases transaction state',
        function (): void {
        [$repository, $transactions] = e012AdministrativeFixture(
            e012AdministrativeEnrollment(EnrollmentStatus::Submitted),
        );
        $repository->saveFailure = new RuntimeException('simulated persistence failure');

        assertThrows(
            static fn () => (new ReopenEnrollment($repository, $transactions))->handle(701),
            RuntimeException::class,
        );

        assertSameValue(false, $transactions->active);
        assertSameValue(1, $transactions->rollbacks);
        assertSameValue('SUBMITTED', (new GetAdministrativeEnrollmentReview($repository))->handle(701)->status);
        },
    );
}

/** @return array{E012AdministrativeEnrollmentRepository, E012AdministrativeTransactionRunner} */
function e012AdministrativeFixture(Enrollment $enrollment): array
{
    $transactions = new E012AdministrativeTransactionRunner();
    $repository = new E012AdministrativeEnrollmentRepository($transactions, [$enrollment]);
    $transactions->repository = $repository;

    return [$repository, $transactions];
}

function e012AdministrativeEnrollment(
    EnrollmentStatus $status,
    bool $submittedHistory = false,
    int $familyId = 801,
): Enrollment {
    $startedAt = new DateTimeImmutable('2026-08-24 09:00:00+00:00');
    $submittedAt = $submittedHistory || $status !== EnrollmentStatus::Draft
        ? new DateTimeImmutable('2026-08-24 10:01:02+00:00')
        : null;
    $completedAt = $status === EnrollmentStatus::Completed
        ? new DateTimeImmutable('2026-08-24 10:11:12+00:00')
        : null;
    $cancelledAt = $status === EnrollmentStatus::Cancelled
        ? new DateTimeImmutable('2026-08-24 10:21:22+00:00')
        : null;

    return Enrollment::reconstitute(
        new EnrollmentId(701),
        new StudentId(501),
        new FamilyId($familyId),
        new AcademicPeriodId(601),
        $status,
        new AcademicPlacement(new GradeId(11), new SectionId(12)),
        new BillingInformation(
            new IdentificationTypeId(13),
            '0912345678',
            'Annual legal name',
            'Annual billing address',
            'annual@example.test',
            'Annual phone',
        ),
        new MedicalInformation(
            false,
            null,
            false,
            null,
            false,
            null,
            false,
            null,
            false,
            null,
            'Annual pediatrician',
            'Annual pediatrician phone',
            'Annual observations',
        ),
        new TransportInformation(true),
        true,
        $startedAt,
        $submittedAt,
        $completedAt,
        $cancelledAt,
    );
}

function e012AssertAdministrativePreserved(EnrollmentOutput $before, EnrollmentOutput $after): void
{
    assertSameValue($before->id, $after->id);
    assertSameValue($before->studentId, $after->studentId);
    assertSameValue($before->familyId, $after->familyId);
    assertSameValue($before->academicPeriodId, $after->academicPeriodId);
    assertSameValue(
        $before->startedAt->format('Y-m-d H:i:s'),
        $after->startedAt->format('Y-m-d H:i:s'),
    );
    assertSameValue(true, $before->academicPlacement == $after->academicPlacement);
    assertSameValue(true, $before->billingInformation == $after->billingInformation);
    assertSameValue(true, $before->medicalInformation == $after->medicalInformation);
    assertSameValue(true, $before->transportInformation == $after->transportInformation);
    assertSameValue($before->isAuthorizedToLeaveAlone, $after->isAuthorizedToLeaveAlone);
}
