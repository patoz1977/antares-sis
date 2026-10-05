<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;
use App\Enrollment\Application\Reporting\AcademicPeriodReportingQuery;
use App\Enrollment\Application\Reporting\Dto\DirectoryRepresentative;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureAuthorizedPickup;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;
use App\Enrollment\Application\Reporting\Dto\ReportingGradeSectionOption;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingSelectionInvalid;
use App\Enrollment\Application\Reporting\GradeSectionReportingQuery;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetPhysicalDepartureReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;
use App\Enrollment\Application\Reporting\PhysicalDepartureReportQuery;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingContext;
use App\Enrollment\Application\Reporting\ResolveInspectionReportingContext;
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

    $runner->add('Directory Application read model keeps Student and two Representative projections atomic', function (): void {
        $representative = new DirectoryRepresentative(
            'Ana',
            null,
            'Pérez',
            null,
            'Madre',
            'Cédula',
            '0102030405',
            null,
            null,
            'ana@example.test',
        );
        $row = new StudentRepresentativeDirectoryRow(
            1,
            null,
            null,
            null,
            null,
            'Luis',
            null,
            'Pérez',
            null,
            null,
            null,
            $representative,
            null,
            null,
            EnrollmentReportingStatus::NotStarted,
        );

        assertSameValue(['Luis', null, 'Pérez', null], [
            $row->studentFirstName,
            $row->studentMiddleName,
            $row->studentFirstSurname,
            $row->studentSecondSurname,
        ]);
        assertSameValue('Madre', $row->representative1?->relationship);
        assertSameValue(null, $row->representative2);
        foreach (['studentNames', 'studentSurnames', 'representativeWorkPhone', 'representativeWorkEmail'] as $legacy) {
            assertSameValue(false, property_exists($row, $legacy));
        }
    });

    $runner->add('E013 Phase 2 report service resolves the selected period before invoking its query port', function (): void {
        $query = new class implements StudentEnrollmentListQuery {
            public ?int $academicPeriodId = null;

            public ?ReportingGradeSectionFilter $filter = null;

            public function fetch(
                int $academicPeriodId,
                ?ReportingGradeSectionFilter $gradeSectionFilter = null,
            ): array
            {
                $this->academicPeriodId = $academicPeriodId;
                $this->filter = $gradeSectionFilter;

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
        assertSameValue(true, $query->filter?->isAll());
        academicPeriodAssertThrows(static fn (): mixed => $service->handle(8), EnrollmentReportingPeriodNotFound::class);
        assertSameValue(7, $query->academicPeriodId);
    });

    $runner->add('Reporting Application resolves actual period options and normalizes duplicate multi-selection', function (): void {
        $options = [
            reportingGradeSection(1, 'EGB_1', '1.º de EGB', 1, 10, 'A', 'A'),
            reportingGradeSection(2, 'EGB_2', '2.º de EGB', 2, 11, 'B', 'B'),
        ];
        $query = new class($options) implements GradeSectionReportingQuery {
            public ?int $academicPeriodId = null;
            public function __construct(private array $options)
            {
            }
            public function findForAcademicPeriod(int $academicPeriodId): array
            {
                $this->academicPeriodId = $academicPeriodId;

                return $this->options;
            }
        };
        $resolver = new ResolveEnrollmentReportingContext(
            new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(8, AcademicPeriodStatus::Active),
            ])),
            $query,
        );

        $all = $resolver->handle(8, null);
        assertSameValue(true, $all->gradeSectionFilter->isAll());
        assertSameValue(['EGB_1:A', 'EGB_2:B'], array_map(
            static fn (ReportingGradeSectionOption $option): string => $option->key(),
            $all->gradeSectionOptions,
        ));

        $selected = $resolver->handle(8, ['EGB_2:B', 'EGB_1:A', 'EGB_2:B']);
        assertSameValue(['EGB_2:B', 'EGB_1:A'], $selected->gradeSectionFilter->keys());
        assertSameValue(8, $selected->gradeSectionFilter->academicPeriodId);
        assertSameValue(8, $query->academicPeriodId);
    });

    $runner->add('Reporting Application rejects malformed unknown incomplete and wrong-period Grade/Section selections', function (): void {
        $resolver = new ResolveEnrollmentReportingContext(
            new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(8, AcademicPeriodStatus::Active),
            ])),
            new class implements GradeSectionReportingQuery {
                public function findForAcademicPeriod(int $academicPeriodId): array
                {
                    return [reportingGradeSection(1, 'EGB_1', '1.º de EGB', 1, 10, 'A', 'A')];
                }
            },
        );

        foreach (['EGB_1:A', 1, [''], ['EGB_1'], ['EGB_1:A:B'], ['EGB_2:A'], ['EGB_1:B']] as $invalid) {
            academicPeriodAssertThrows(
                static fn (): mixed => $resolver->handle(8, $invalid),
                EnrollmentReportingSelectionInvalid::class,
            );
        }
    });

    $runner->add('Reporting filter is bound to its resolved AcademicPeriod before any report query', function (): void {
        $query = new class implements StudentEnrollmentListQuery {
            public int $calls = 0;
            public function fetch(
                int $academicPeriodId,
                ?ReportingGradeSectionFilter $gradeSectionFilter = null,
            ): array {
                ++$this->calls;

                return [];
            }
        };
        $service = new GetStudentEnrollmentReport(
            new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(8, AcademicPeriodStatus::Active),
            ])),
            $query,
        );

        academicPeriodAssertThrows(
            static fn (): mixed => $service->handle(8, ReportingGradeSectionFilter::all(7)),
            \InvalidArgumentException::class,
        );
        assertSameValue(0, $query->calls);
    });

    $runner->add('Phase 5 inspection context resolves only the server-side ACTIVE period and reuses filter validation', function (): void {
        $repository = new InMemoryAcademicPeriodRepository([
            academicPeriodFixture(7, AcademicPeriodStatus::Inactive),
            academicPeriodFixture(8, AcademicPeriodStatus::Active),
        ]);
        $options = [
            reportingGradeSection(1, 'EGB_1', '1.º de EGB', 1, 10, 'A', 'A'),
            reportingGradeSection(2, 'EGB_2', '2.º de EGB', 2, 11, 'B', 'B'),
        ];
        $coordinator = new ResolveInspectionReportingContext(
            new GetActiveAcademicPeriod($repository),
            new ResolveEnrollmentReportingContext(
                new ResolveEnrollmentReportingPeriod($repository),
                new class($options) implements GradeSectionReportingQuery {
                    public function __construct(private array $options) {}
                    public function findForAcademicPeriod(int $academicPeriodId): array { return $this->options; }
                },
            ),
        );

        $all = $coordinator->handle(null, null);
        assertSameValue([8, true], [$all->academicPeriod->id, $all->gradeSectionFilter->isAll()]);
        $selected = $coordinator->handle('8', ['EGB_2:B', 'EGB_1:A', 'EGB_2:B']);
        assertSameValue(['EGB_2:B', 'EGB_1:A'], $selected->gradeSectionFilter->keys());
        foreach ([['7', null], ['invalid', null], [null, ['UNKNOWN:A']], [null, 'EGB_1:A']] as [$period, $filter]) {
            academicPeriodAssertThrows(
                static fn (): mixed => $coordinator->handle($period, $filter),
                $period !== null ? \InvalidArgumentException::class : EnrollmentReportingSelectionInvalid::class,
            );
        }

        foreach ([
            new InMemoryAcademicPeriodRepository([academicPeriodFixture(7, AcademicPeriodStatus::Inactive)]),
            new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(7, AcademicPeriodStatus::Active),
                academicPeriodFixture(8, AcademicPeriodStatus::Active),
            ]),
        ] as $index => $invalidRepository) {
            $invalid = new ResolveInspectionReportingContext(
                new GetActiveAcademicPeriod($invalidRepository),
                new ResolveEnrollmentReportingContext(new ResolveEnrollmentReportingPeriod($invalidRepository), new class implements GradeSectionReportingQuery {
                    public function findForAcademicPeriod(int $academicPeriodId): array { return []; }
                }),
            );
            academicPeriodAssertThrows(
                static fn (): mixed => $invalid->handle(null, null),
                $index === 0 ? EnrollmentReportingPeriodNotFound::class : AcademicPeriodOperationalStateConflict::class,
            );
        }
    });

    $runner->add('Phase 5 departure read model is immutable and derives exactly four approved states', function (): void {
        $pickup = new PhysicalDepartureAuthorizedPickup('Pickup', 'Madre', null, null, '0990000000');
        $rows = [
            new PhysicalDepartureReportRow(1, null, null, 'A', null, 'One', null, null, null, null, [$pickup]),
            new PhysicalDepartureReportRow(2, null, null, 'B', null, 'Two', null, null, null, true, []),
            new PhysicalDepartureReportRow(3, null, null, 'C', null, 'Three', null, null, null, false, [$pickup]),
            new PhysicalDepartureReportRow(4, null, null, 'D', null, 'Four', null, null, null, false, []),
        ];
        assertSameValue([
            'Sin matrícula', 'Sí puede salir solo', 'No puede salir solo',
            'Sin persona autorizada registrada',
        ], array_map(static fn (PhysicalDepartureReportRow $row): string => $row->departureState->value, $rows));
        assertSameValue(true, (new \ReflectionClass(PhysicalDepartureReportRow::class))->isReadOnly());
        assertSameValue(true, (new \ReflectionClass(PhysicalDepartureAuthorizedPickup::class))->isReadOnly());

        $query = new class($rows) implements PhysicalDepartureReportQuery {
            public int $calls = 0;
            public function __construct(private array $rows) {}
            public function fetch(int $academicPeriodId, ?ReportingGradeSectionFilter $gradeSectionFilter = null): array
            {
                ++$this->calls;
                return $this->rows;
            }
        };
        $context = (new ResolveInspectionReportingContext(
            new GetActiveAcademicPeriod(new InMemoryAcademicPeriodRepository([
                academicPeriodFixture(8, AcademicPeriodStatus::Active),
            ])),
            new ResolveEnrollmentReportingContext(
                new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
                    academicPeriodFixture(8, AcademicPeriodStatus::Active),
                ])),
                new class implements GradeSectionReportingQuery {
                    public function findForAcademicPeriod(int $academicPeriodId): array { return []; }
                },
            ),
        ))->handle(null, null);
        assertSameValue(4, count((new GetPhysicalDepartureReport($query))->handle($context)));
        assertSameValue(1, $query->calls);
    });

    $runner->add('E013 Phase 2 production wiring registers only reporting ports adapters and Application services', function (): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        foreach ([
            'AcademicPeriodReportingQuery::class, PdoAcademicPeriodReportingQuery::class',
            'GradeSectionReportingQuery::class, PdoGradeSectionReportingQuery::class',
            'EnrollmentSummaryQuery::class, PdoEnrollmentSummaryQuery::class',
            'StudentEnrollmentListQuery::class, PdoStudentEnrollmentListQuery::class',
            'StudentBillingReportQuery::class, PdoStudentBillingReportQuery::class',
            'StudentMedicalReportQuery::class, PdoStudentMedicalReportQuery::class',
            'GetEnrollmentReportingPeriods::class, GetEnrollmentReportingPeriods::class',
            'ResolveEnrollmentReportingContext::class, ResolveEnrollmentReportingContext::class',
            'GetEnrollmentSummaryReport::class, GetEnrollmentSummaryReport::class',
        ] as $binding) {
            assertSameValue(true, str_contains($source, $binding));
        }
        assertSameValue(false, str_contains($source, 'EnrollmentReportingController::class, Pdo'));
        assertSameValue(false, str_contains($source, 'EnrollmentReportCsvWriter::class, Pdo'));
    });
}

function reportingGradeSection(
    int $gradeId,
    string $gradeCode,
    string $gradeName,
    int $gradeSortOrder,
    int $sectionId,
    string $sectionCode,
    string $sectionName,
): ReportingGradeSectionOption {
    return new ReportingGradeSectionOption(
        $gradeId,
        $gradeCode,
        $gradeName,
        $gradeSortOrder,
        $sectionId,
        $sectionCode,
        $sectionName,
    );
}

function reportingPeriod(int $id, AcademicPeriodStatus $status): ReportingAcademicPeriod
{
    return new ReportingAcademicPeriod($id, 'P' . $id, 'Period ' . $id, '2026-09-01', '2027-06-30', $status);
}
