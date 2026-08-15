<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestRunner;
use Throwable;

function registerAcademicPeriodDomainTests(TestRunner $runner): void
{
    $runner->add('E009 Phase 5.1 AcademicPeriod identities labels and dates preserve approved invariants', function (): void {
        $id = new AcademicPeriodId(7);
        assertSameValue(7, $id->value());
        assertSameValue('2026', (new AcademicPeriodCode(' 2026 '))->value());
        assertSameValue('School Year 2026', (new AcademicPeriodName(' School Year 2026 '))->value());

        $dates = new AcademicPeriodDateRange(
            new DateTimeImmutable('2026-09-01 23:30:00', new DateTimeZone('Pacific/Kiritimati')),
            new DateTimeImmutable('2027-06-30 01:30:00', new DateTimeZone('America/Bogota')),
        );
        assertSameValue('2026-09-01', $dates->startsOn()->format('Y-m-d'));
        assertSameValue('2027-06-30', $dates->endsOn()->format('Y-m-d'));
        assertSameValue('UTC', $dates->startsOn()->getTimezone()->getName());
    });

    $runner->add('E009 Phase 5.1 AcademicPeriod rejects invalid identity labels and date order', function (): void {
        foreach ([
            static fn (): mixed => new AcademicPeriodId(0),
            static fn (): mixed => new AcademicPeriodCode(' '),
            static fn (): mixed => new AcademicPeriodCode(str_repeat('c', 101)),
            static fn (): mixed => new AcademicPeriodName(' '),
            static fn (): mixed => new AcademicPeriodName(str_repeat('n', 151)),
            static fn (): mixed => new AcademicPeriodDateRange(
                new DateTimeImmutable('2027-01-01'),
                new DateTimeImmutable('2026-12-31'),
            ),
        ] as $operation) {
            academicPeriodAssertThrows($operation, InvalidAcademicPeriodState::class);
        }
    });

    $runner->add('E009 Phase 5.1 AcademicPeriod lifecycle is explicit and never inferred from dates', function (): void {
        $future = academicPeriodFixture(8, AcademicPeriodStatus::Inactive, '2040-01-01', '2040-12-31');
        assertSameValue(false, $future->isActive());
        $future->activate();
        assertSameValue(true, $future->isActive());
        assertSameValue(AcademicPeriodStatus::Active, $future->status());
        $future->deactivate();
        assertSameValue(false, $future->isActive());
        assertSameValue(8, $future->id()?->value());
        assertSameValue(false, method_exists($future, 'setId'));
    });
}

function academicPeriodFixture(
    int $id,
    AcademicPeriodStatus $status,
    string $startsOn = '2026-09-01',
    string $endsOn = '2027-06-30',
): \App\AcademicCore\Domain\AcademicPeriod {
    return new \App\AcademicCore\Domain\AcademicPeriod(
        new AcademicPeriodId($id),
        new AcademicPeriodCode('P' . $id),
        new AcademicPeriodName('Period ' . $id),
        new AcademicPeriodDateRange(new DateTimeImmutable($startsOn), new DateTimeImmutable($endsOn)),
        $status,
    );
}

function academicPeriodAssertThrows(callable $operation, string $expectedClass): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        assertSameValue($expectedClass, $exception::class);

        return;
    }

    throw new \RuntimeException('Expected exception ' . $expectedClass . '.');
}
