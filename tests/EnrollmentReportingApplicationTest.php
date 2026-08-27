<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;
use App\Enrollment\Application\Reporting\AcademicPeriodReportingQuery;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Enrollment\Application\Reporting\StudentEnrollmentListQuery;
use Tests\Support\TestRunner;

function registerEnrollmentReportingApplicationTests(TestRunner $runner): void
{
    $runner->add('E013 Phase 2 reporting periods expose ACTIVE and INACTIVE with one safe default', function (): void {
        $query = new class implements AcademicPeriodReportingQuery {
            public function findAll(): array
            {
                return [reportingPeriod(7, AcademicPeriodStatus::Inactive), reportingPeriod(8, AcademicPeriodStatus::Active)];
            }
        };
        $result = (new GetEnrollmentReportingPeriods($query))->handle();
        assertSameValue([7, 8], array_map(static fn (ReportingAcademicPeriod $period): int => $period->id, $result->periods));
        assertSameValue(8, $result->defaultAcademicPeriodId);
    });

    $runner->add('E013 Phase 2 reporting period default is null with zero ACTIVE and fails with multiple ACTIVE', function (): void {
        $none = new class implements AcademicPeriodReportingQuery {
            public function findAll(): array
            {
                return [reportingPeriod(7, AcademicPeriodStatus::Inactive)];
            }
        };
        assertSameValue(null, (new GetEnrollmentReportingPeriods($none))->handle()->defaultAcademicPeriodId);

        $multiple = new class implements AcademicPeriodReportingQuery {
            public function findAll(): array
            {
                return [reportingPeriod(7, AcademicPeriodStatus::Active), reportingPeriod(8, AcademicPeriodStatus::Active)];
            }
        };
        academicPeriodAssertThrows(
            static fn (): mixed => (new GetEnrollmentReportingPeriods($multiple))->handle(),
            AcademicPeriodOperationalStateConflict::class,
        );
    });

    $runner->add('E013 Phase 2 explicit reporting selection accepts ACTIVE and INACTIVE and rejects invalid or absent IDs', function (): void {
        $resolver = new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(7, AcademicPeriodStatus::Inactive),
            academicPeriodFixture(8, AcademicPeriodStatus::Active),
        ]));
        assertSameValue(AcademicPeriodStatus::Inactive, $resolver->handle(7)->status);
        assertSameValue(AcademicPeriodStatus::Active, $resolver->handle(8)->status);
        academicPeriodAssertThrows(static fn (): mixed => $resolver->handle(99), EnrollmentReportingPeriodNotFound::class);
        academicPeriodAssertThrows(static fn (): mixed => $resolver->handle(0), InvalidAcademicPeriodState::class);
    });

    $runner->add('E013 Phase 2 derives exactly the five reporting statuses without persisting NOT STARTED', function (): void {
        assertSameValue('NOT STARTED', EnrollmentReportingStatus::fromEnrollmentCode(null)->value);
        foreach (['DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'] as $code) {
            assertSameValue($code, EnrollmentReportingStatus::fromEnrollmentCode($code)->value);
        }
        academicPeriodAssertThrows(
            static fn (): mixed => EnrollmentReportingStatus::fromEnrollmentCode('NOT STARTED'),
            \RuntimeException::class,
        );
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Application/Reporting/EnrollmentReportingStatus.php');
        assertSameValue(false, str_contains($source, 'EnrollmentRepository'));
        assertSameValue(false, str_contains($source, 'save('));
    });

    $runner->add('E013 Phase 2 report service resolves the selected period before invoking its query port', function (): void {
        $query = new class implements StudentEnrollmentListQuery {
            public ?int $academicPeriodId = null;

            public function fetch(int $academicPeriodId): array
            {
                $this->academicPeriodId = $academicPeriodId;

                return [new StudentEnrollmentReportRow(
                    1, null, null, null, null, 'Surname', 'Name', EnrollmentReportingStatus::NotStarted,
                )];
            }
        };
        $service = new GetStudentEnrollmentReport(
            new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(7, AcademicPeriodStatus::Inactive),
            ])),
            $query,
        );
        assertSameValue('NOT STARTED', $service->handle(7)[0]->status->value);
        assertSameValue(7, $query->academicPeriodId);
        academicPeriodAssertThrows(static fn (): mixed => $service->handle(8), EnrollmentReportingPeriodNotFound::class);
        assertSameValue(7, $query->academicPeriodId);
    });

    $runner->add('E013 Phase 2 production wiring registers only reporting ports adapters and Application services', function (): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        foreach ([
            'AcademicPeriodReportingQuery::class, PdoAcademicPeriodReportingQuery::class',
            'EnrollmentSummaryQuery::class, PdoEnrollmentSummaryQuery::class',
            'StudentEnrollmentListQuery::class, PdoStudentEnrollmentListQuery::class',
            'StudentBillingReportQuery::class, PdoStudentBillingReportQuery::class',
            'StudentMedicalReportQuery::class, PdoStudentMedicalReportQuery::class',
            'GetEnrollmentReportingPeriods::class, GetEnrollmentReportingPeriods::class',
            'GetEnrollmentSummaryReport::class, GetEnrollmentSummaryReport::class',
        ] as $binding) {
            assertSameValue(true, str_contains($source, $binding));
        }
        assertSameValue(false, str_contains($source, 'ReportingController'));
        assertSameValue(false, str_contains($source, 'CsvWriter'));
    });
}

function reportingPeriod(int $id, AcademicPeriodStatus $status): ReportingAcademicPeriod
{
    return new ReportingAcademicPeriod($id, 'P' . $id, 'Period ' . $id, '2026-09-01', '2027-06-30', $status);
}
