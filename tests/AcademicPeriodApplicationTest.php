<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\ActivateAcademicPeriod;
use App\AcademicCore\Application\DeactivateAcademicPeriod;
use App\AcademicCore\Application\Exception\AcademicPeriodNotFound;
use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use RuntimeException;
use Tests\Support\TestRunner;

function registerAcademicPeriodApplicationTests(TestRunner $runner): void
{
    $runner->add('E009 Phase 5.1 GetActiveAcademicPeriod returns safe persisted output or null', function (): void {
        $none = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Inactive),
        ]);
        assertSameValue(null, (new GetActiveAcademicPeriod($none))->handle());

        $active = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(2, AcademicPeriodStatus::Active, '2040-01-01', '2040-12-31'),
        ]);
        $output = (new GetActiveAcademicPeriod($active))->handle();
        assertSameValue([2, 'P2', 'Period 2', '2040-01-01', '2040-12-31', 'ACTIVE'], [
            $output?->id, $output?->code, $output?->name, $output?->startsOn, $output?->endsOn, $output?->status,
        ]);
    });

    $runner->add('E009 Phase 5.1 activating B atomically deactivates A under one serialized transition', function (): void {
        $repository = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Active),
            academicPeriodFixture(2, AcademicPeriodStatus::Inactive),
        ]);
        $transactions = new InMemoryCompositeTransactionRunner([$repository]);
        $output = (new ActivateAcademicPeriod($repository, $transactions))->handle(2);
        assertSameValue([2, 'ACTIVE'], [$output->id, $output->status]);
        assertSameValue('INACTIVE', $repository->findById(new AcademicPeriodId(1))?->status()->value);
        assertSameValue(2, $repository->findActive()?->id()?->value());
        assertSameValue(2, $repository->saveCount);
        assertSameValue(1, $repository->lockCount);
        assertSameValue(1, $transactions->commitCount());
    });

    $runner->add('E009 Phase 5.1 activation and deactivation are idempotent and deactivation may leave zero', function (): void {
        $repository = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Active),
            academicPeriodFixture(2, AcademicPeriodStatus::Inactive),
        ]);
        $transactions = new InMemoryCompositeTransactionRunner([$repository]);
        (new ActivateAcademicPeriod($repository, $transactions))->handle(1);
        assertSameValue(0, $repository->saveCount);
        (new DeactivateAcademicPeriod($repository, $transactions))->handle(1);
        assertSameValue(null, $repository->findActive());
        assertSameValue(1, $repository->saveCount);
        (new DeactivateAcademicPeriod($repository, $transactions))->handle(1);
        assertSameValue(1, $repository->saveCount);
    });

    $runner->add('E009 Phase 5.1 failed activation rolls back the prior deactivation', function (): void {
        $repository = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Active),
            academicPeriodFixture(2, AcademicPeriodStatus::Inactive),
        ]);
        $repository->failOnSaveNumber = 2;
        $transactions = new InMemoryCompositeTransactionRunner([$repository]);
        academicPeriodAssertThrows(
            static fn (): mixed => (new ActivateAcademicPeriod($repository, $transactions))->handle(2),
            RuntimeException::class,
        );
        assertSameValue(1, $repository->findActive()?->id()?->value());
        assertSameValue('INACTIVE', $repository->findById(new AcademicPeriodId(2))?->status()->value);
        assertSameValue(1, $transactions->rollbackCount());
    });

    $runner->add('E009 Phase 5.1 transitions fail closed for absent target or multiple active periods', function (): void {
        $missing = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Inactive),
        ]);
        academicPeriodAssertThrows(
            static fn (): mixed => (new ActivateAcademicPeriod(
                $missing,
                new InMemoryCompositeTransactionRunner([$missing]),
            ))->handle(99),
            AcademicPeriodNotFound::class,
        );

        $corrupt = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(1, AcademicPeriodStatus::Active),
            academicPeriodFixture(2, AcademicPeriodStatus::Active),
        ]);
        academicPeriodAssertThrows(
            static fn (): mixed => (new ActivateAcademicPeriod(
                $corrupt,
                new InMemoryCompositeTransactionRunner([$corrupt]),
            ))->handle(1),
            AcademicPeriodOperationalStateConflict::class,
        );
        assertSameValue(0, $corrupt->saveCount);
    });
}
