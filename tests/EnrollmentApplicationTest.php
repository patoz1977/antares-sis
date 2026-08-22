<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicSectionReference;
use App\AcademicCore\Application\GetNextActiveGrade;
use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPlacementReferenceProvider;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentAcademicPlacementInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentBillingInformationInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentLeaveAloneAuthorizationInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentMedicalInformationInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentTransportInformationInput;
use App\Enrollment\Application\Exception\AcademicPlacementReferenceNotFound;
use App\Enrollment\Application\Exception\EnrollmentAcademicPeriodNotFound;
use App\Enrollment\Application\Exception\EnrollmentAlreadyExists;
use App\Enrollment\Application\Exception\EnrollmentContextMismatch;
use App\Enrollment\Application\Exception\EnrollmentFamilyContextUnavailable;
use App\Enrollment\Application\Exception\EnrollmentNotFound;
use App\Enrollment\Application\Exception\EnrollmentPersistedStateMismatch;
use App\Enrollment\Application\Exception\EnrollmentStudentNotFound;
use App\Enrollment\Application\GetEnrollment;
use App\Enrollment\Application\GetEnrollmentByStudentAndAcademicPeriod;
use App\Enrollment\Application\StartEnrollmentDraft;
use App\Enrollment\Application\UpdateEnrollmentAcademicPlacement;
use App\Enrollment\Application\UpdateEnrollmentBillingInformation;
use App\Enrollment\Application\UpdateEnrollmentLeaveAloneAuthorization;
use App\Enrollment\Application\UpdateEnrollmentMedicalInformation;
use App\Enrollment\Application\UpdateEnrollmentTransportInformation;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId as EnrollmentFamilyId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\Contract\Clock;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentApplicationTests(TestRunner $runner): void
{
    $runner->add('Enrollment Application starts empty Draft from validated trusted context and Clock', function (): void {
        [$start, $repository, $transactions, $clock, , $families, $trace] = e010ApplicationStartFixture();

        $output = $start->handle(new StartEnrollmentDraftInput(20, 30, 40));

        assertSameValue(true, $output->id > 0);
        assertSameValue([20, 30, 40, 'DRAFT'], [
            $output->studentId, $output->familyId, $output->academicPeriodId, $output->status,
        ]);
        assertSameValue(null, $output->academicPlacement);
        assertSameValue(null, $output->billingInformation);
        assertSameValue(null, $output->medicalInformation);
        assertSameValue(null, $output->transportInformation);
        assertSameValue(false, $output->isAuthorizedToLeaveAlone);
        assertSameValue(false, $output->hasSubmissionSnapshot);
        assertSameValue('2026-08-21 14:15:16', $output->startedAt->format('Y-m-d H:i:s'));
        assertSameValue(1, $clock->calls);
        assertSameValue(1, $repository->saveCalls);
        assertSameValue(1, $families->lockCalls);
        assertSameValue(0, $families->ordinaryCalls);
        assertSameValue(true, $families->lockObservedActiveTransaction);
        assertSameValue(['family-lock', 'enrollment-save'], $trace->events);
        assertSameValue(['begin', 'commit'], $transactions->events);
    });

    $runner->add('Enrollment Application initialization rejects every missing or mismatched prerequisite', function (): void {
        [$start] = e010ApplicationStartFixture(studentExists: false);
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            EnrollmentStudentNotFound::class,
        );

        [$start] = e010ApplicationStartFixture(familyId: null);
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            EnrollmentFamilyContextUnavailable::class,
        );

        [$start] = e010ApplicationStartFixture(familyId: 31);
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            EnrollmentFamilyContextUnavailable::class,
        );

        [$start] = e010ApplicationStartFixture(periodExists: false);
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            EnrollmentAcademicPeriodNotFound::class,
        );
    });

    $runner->add('Enrollment Application rejects existing Student Period and preserves physical race errors', function (): void {
        [$start, $repository] = e010ApplicationStartFixture();
        $start->handle(new StartEnrollmentDraftInput(20, 30, 40));
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            EnrollmentAlreadyExists::class,
        );
        assertSameValue(1, $repository->saveCalls);

        [$start, $repository] = e010ApplicationStartFixture();
        $repository->saveFailure = new RuntimeException('physical unique');
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40)),
            RuntimeException::class,
        );
    });

    $runner->add('Enrollment Application validates optional placement without ACTIVE or compatibility assumptions', function (): void {
        [$start, , , , $references] = e010ApplicationStartFixture();
        $output = $start->handle(new StartEnrollmentDraftInput(20, 30, 40, 2, 9));
        assertSameValue([2, 9], [$output->academicPlacement?->gradeId, $output->academicPlacement?->sectionId]);

        $references->grades = [];
        [$start] = e010ApplicationStartFixture(references: $references);
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40, 2)),
            AcademicPlacementReferenceNotFound::class,
        );

        [$start] = e010ApplicationStartFixture();
        assertThrows(
            static fn () => $start->handle(new StartEnrollmentDraftInput(20, 30, 40, null, 9)),
            AcademicPlacementReferenceNotFound::class,
        );
    });

    $runner->add('Academic Core next Grade returns immediate strictly greater ACTIVE reference', function (): void {
        $references = e010AcademicReferences();
        $next = (new GetNextActiveGrade($references))->handle(2);
        assertSameValue([3, 30, 'ACTIVE'], [$next?->id, $next?->sortOrder, $next?->status]);
        assertSameValue(null, (new GetNextActiveGrade($references))->handle(3));
    });

    $runner->add('Academic Core PDO references preserve GENERAL_STATUS and next ACTIVE ordering', function (): void {
        $manager = familySqliteManager();
        $pdo = $manager->connection();
        $pdo->exec(
            'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
            . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
            . 'CREATE TABLE grades (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, '
            . 'sort_order INTEGER NOT NULL, status_id INTEGER NOT NULL);'
            . 'CREATE TABLE sections (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, '
            . 'status_id INTEGER NOT NULL);'
            . "INSERT INTO status_types VALUES (1, 'GENERAL_STATUS');"
            . "INSERT INTO statuses VALUES (1, 1, 'ACTIVE'), (2, 1, 'INACTIVE');"
            . "INSERT INTO grades VALUES (10, 'G1', 'Grade 1', 10, 1), "
            . "(11, 'G2', 'Grade 2', 20, 2), (12, 'G3', 'Grade 3', 30, 1);"
            . "INSERT INTO sections VALUES (20, 'A', 'Section A', 2);"
        );
        $provider = new PdoAcademicPlacementReferenceProvider($manager);

        assertSameValue('INACTIVE', $provider->findGradeById(11)?->status);
        assertSameValue('INACTIVE', $provider->findSectionById(20)?->status);
        assertSameValue(12, $provider->findNextActiveGradeAfterSortOrder(10)?->id);
        assertSameValue(null, $provider->findGradeById(999));
        assertSameValue(null, $provider->findSectionById(999));
    });

    $runner->add('Enrollment Application gets readonly state by id and Student Period', function (): void {
        [$start, $repository] = e010ApplicationStartFixture();
        $created = $start->handle(new StartEnrollmentDraftInput(20, 30, 40));

        $byId = (new GetEnrollment($repository))->handle($created->id);
        $byPair = (new GetEnrollmentByStudentAndAcademicPeriod($repository))->handle(20, 40);

        assertSameValue($created->id, $byId->id);
        assertSameValue($created->id, $byPair?->id);
        assertSameValue(30, $byPair?->familyId);
        assertSameValue(null, (new GetEnrollmentByStudentAndAcademicPeriod($repository))->handle(20, 41));
        assertThrows(static fn () => (new GetEnrollment($repository))->handle(999), EnrollmentNotFound::class);
    });

    $runner->add('Enrollment Application serializes placement update and accepts nullable Section', function (): void {
        [$repository, $transactions, $references, $id] = e010ApplicationPersistedFixture();
        $useCase = new UpdateEnrollmentAcademicPlacement($repository, $references, $transactions);

        $withSection = $useCase->handle(new UpdateEnrollmentAcademicPlacementInput(
            $id, 20, 30, 40, 2, 9,
        ));
        $withoutSection = $useCase->handle(new UpdateEnrollmentAcademicPlacementInput(
            $id, 20, 30, 40, 3, null,
        ));

        assertSameValue([2, 9], [$withSection->academicPlacement?->gradeId, $withSection->academicPlacement?->sectionId]);
        assertSameValue([3, null], [$withoutSection->academicPlacement?->gradeId, $withoutSection->academicPlacement?->sectionId]);
        assertSameValue(2, $repository->lockCalls);
        assertSameValue(['lock', 'save', 'lock', 'save'], $repository->sequence);
        assertSameValue(['begin', 'commit', 'begin', 'commit'], $transactions->events);
    });

    $runner->add('Enrollment Application updates Billing and preserves unrelated annual state', function (): void {
        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
        $input = new UpdateEnrollmentBillingInformationInput(
            $id, 20, 30, 40, 1, '0912345678', 'Familia Ñ', 'Av. Uno',
            'billing@example.test', '+593 99 000 0000',
        );
        $output = (new UpdateEnrollmentBillingInformation($repository, $transactions))->handle($input);

        assertSameValue('Familia Ñ', $output->billingInformation?->legalName);
        assertSameValue(null, $output->medicalInformation);
        assertSameValue(null, $output->transportInformation);
        assertThrows(
            static fn () => (new UpdateEnrollmentBillingInformation($repository, $transactions))->handle(
                new UpdateEnrollmentBillingInformationInput(
                    $id, 20, 30, 40, 1, '', 'Name', 'Address', 'bad-email', 'Phone',
                ),
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Enrollment Application retains all Medical conditional rules', function (): void {
        foreach ([
            [true, null, false, null, false, null, false, null, false, null],
            [false, null, true, null, false, null, false, null, false, null],
            [false, null, false, null, true, null, false, null, false, null],
            [false, null, false, null, false, null, true, null, false, null],
            [false, null, false, null, false, null, false, null, true, null],
        ] as $answers) {
            [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
            [
                $hasMedicalCondition,
                $medicalConditionDetail,
                $hasAllergies,
                $allergyDetail,
                $takesPermanentMedication,
                $medicationName,
                $requiresSpecialCare,
                $specialCareDetail,
                $hasMedicalInsurance,
                $insuranceProvider,
            ] = $answers;
            assertThrows(
                static fn () => (new UpdateEnrollmentMedicalInformation($repository, $transactions))->handle(
                    new UpdateEnrollmentMedicalInformationInput(
                        $id,
                        20,
                        30,
                        40,
                        $hasMedicalCondition,
                        $medicalConditionDetail,
                        $hasAllergies,
                        $allergyDetail,
                        $takesPermanentMedication,
                        $medicationName,
                        $requiresSpecialCare,
                        $specialCareDetail,
                        $hasMedicalInsurance,
                        $insuranceProvider,
                        null,
                        null,
                        null,
                    ),
                ),
                InvalidEnrollmentState::class,
            );
        }

        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
        $output = (new UpdateEnrollmentMedicalInformation($repository, $transactions))->handle(
            e010ValidMedicalInput($id),
        );
        assertSameValue('Condition', $output->medicalInformation?->medicalConditionDetail);
        assertSameValue(false, $output->isAuthorizedToLeaveAlone);
    });

    $runner->add('Enrollment Application updates Transport and leave-alone booleans both ways', function (): void {
        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
        $transport = new UpdateEnrollmentTransportInformation($repository, $transactions);
        assertSameValue(true, $transport->handle(new UpdateEnrollmentTransportInformationInput(
            $id, 20, 30, 40, true,
        ))->transportInformation?->requiresInstitutionalTransport);
        assertSameValue(false, $transport->handle(new UpdateEnrollmentTransportInformationInput(
            $id, 20, 30, 40, false,
        ))->transportInformation?->requiresInstitutionalTransport);

        $leave = new UpdateEnrollmentLeaveAloneAuthorization($repository, $transactions);
        assertSameValue(true, $leave->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput(
            $id, 20, 30, 40, true,
        ))->isAuthorizedToLeaveAlone);
        assertSameValue(false, $leave->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput(
            $id, 20, 30, 40, false,
        ))->isAuthorizedToLeaveAlone);
    });

    $runner->add('Enrollment Application fails closed for context mismatch before mutation', function (): void {
        foreach ([[21, 30, 40], [20, 31, 40], [20, 30, 41]] as [$student, $family, $period]) {
            [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
            assertThrows(
                static fn () => (new UpdateEnrollmentLeaveAloneAuthorization(
                    $repository,
                    $transactions,
                ))->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput(
                    $id, $student, $family, $period, true,
                )),
                EnrollmentContextMismatch::class,
            );
            assertSameValue(0, $repository->saveCalls);
            assertSameValue(['begin', 'rollback'], $transactions->events);
        }
    });

    $runner->add('Enrollment Application mutation requires locked row and verifies returned full state', function (): void {
        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
        $repository->remove($id);
        assertThrows(
            static fn () => (new UpdateEnrollmentLeaveAloneAuthorization(
                $repository,
                $transactions,
            ))->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput($id, 20, 30, 40, true)),
            EnrollmentNotFound::class,
        );

        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture();
        $repository->corruptSaveResult = true;
        assertThrows(
            static fn () => (new UpdateEnrollmentLeaveAloneAuthorization(
                $repository,
                $transactions,
            ))->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput($id, 20, 30, 40, true)),
            EnrollmentPersistedStateMismatch::class,
        );
        assertSameValue(['begin', 'rollback'], $transactions->events);
    });

    $runner->add('Enrollment Application enforces Draft-only Domain mutations and architectural boundaries', function (): void {
        [$repository, $transactions, , $id] = e010ApplicationPersistedFixture(EnrollmentStatus::Cancelled);
        assertThrows(
            static fn () => (new UpdateEnrollmentLeaveAloneAuthorization(
                $repository,
                $transactions,
            ))->handle(new UpdateEnrollmentLeaveAloneAuthorizationInput($id, 20, 30, 40, true)),
            InvalidEnrollmentState::class,
        );

        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../app/Enrollment/Application',
        )) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Request', 'Response',
            'SessionManager', 'Controller', 'Infrastructure\\',
            'SubmissionService', 'submit(', 'reopen(', 'complete(', 'cancel(']
            as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        assertSameValue(false, is_dir(__DIR__ . '/../app/Enrollment/Delivery'));
    });
}

/** @return array{StartEnrollmentDraft, E010InMemoryEnrollmentRepository, E010FakeTransactionRunner, E010FakeClock, E010AcademicReferences, E010FamilyRepository, E010InitializationTrace} */
function e010ApplicationStartFixture(
    bool $studentExists = true,
    ?int $familyId = 30,
    bool $periodExists = true,
    ?E010AcademicReferences $references = null,
): array {
    $transactions = new E010FakeTransactionRunner();
    $trace = new E010InitializationTrace();
    $repository = new E010InMemoryEnrollmentRepository($transactions, $trace);
    $clock = new E010FakeClock(new DateTimeImmutable('2026-08-21 14:15:16.987654+00:00'));
    $academicReferences = $references ?? e010AcademicReferences();
    $families = new E010FamilyRepository(
        $familyId === null ? null : e010Family($familyId),
        $transactions,
        $trace,
    );
    $start = new StartEnrollmentDraft(
        new E010StudentRepository($studentExists ? e010Student(20) : null),
        $families,
        new E010AcademicPeriodRepository($periodExists ? e010AcademicPeriod(40) : null),
        $repository,
        $academicReferences,
        $clock,
        $transactions,
    );

    return [$start, $repository, $transactions, $clock, $academicReferences, $families, $trace];
}

/** @return array{E010InMemoryEnrollmentRepository, E010FakeTransactionRunner, E010AcademicReferences, int} */
function e010ApplicationPersistedFixture(EnrollmentStatus $status = EnrollmentStatus::Draft): array
{
    $transactions = new E010FakeTransactionRunner();
    $repository = new E010InMemoryEnrollmentRepository($transactions);
    $draft = Enrollment::startDraft(
        new EnrollmentStudentId(20),
        new EnrollmentFamilyId(30),
        new AcademicPeriodId(40),
        new DateTimeImmutable('2026-08-21 14:15:16+00:00'),
    );
    if ($status === EnrollmentStatus::Cancelled) {
        $draft->cancel(new DateTimeImmutable('2026-08-21 14:16:00+00:00'));
    }
    $persisted = $repository->seed($draft);
    $id = $persisted->id()?->value() ?? throw new RuntimeException('Fixture identity missing.');
    $repository->resetObservations();

    return [$repository, $transactions, e010AcademicReferences(), $id];
}

function e010ValidMedicalInput(int $id): UpdateEnrollmentMedicalInformationInput
{
    return new UpdateEnrollmentMedicalInformationInput(
        $id, 20, 30, 40,
        true, 'Condition', true, 'Allergy', true, 'Medication', true, 'Care', true, 'Insurance',
        'Pediatrician', 'Phone', 'Observations',
    );
}

function e010AcademicReferences(): E010AcademicReferences
{
    $provider = new E010AcademicReferences();
    $provider->grades = [
        2 => new AcademicGradeReference(2, 'G2', 'Grade 2', 'INACTIVE', 20),
        3 => new AcademicGradeReference(3, 'G3', 'Grade 3', 'ACTIVE', 30),
    ];
    $provider->sections = [
        9 => new AcademicSectionReference(9, 'A', 'Section A', 'INACTIVE'),
    ];

    return $provider;
}

function e010Student(int $id): Student
{
    return new Student(
        new StudentId($id),
        new PersonId(10),
        new InstitutionalCode('STUDENT-' . $id),
        new AdmissionDate(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-21')),
        StudentStatus::Inactive,
    );
}

function e010Family(int $id): Family
{
    return Family::reconstitute(
        new FamilyId($id),
        new DisplayName('Family ' . $id),
        FamilyStatus::Active,
        [new FamilyRepresentative(
            new FamilyRepresentativeId(1),
            new RepresentativeId(1),
            new RelationshipTypeId(1),
            true,
            new DateTimeImmutable('2026-08-01 00:00:00+00:00'),
            null,
        )],
        [],
    );
}

function e010AcademicPeriod(int $id): AcademicPeriod
{
    return new AcademicPeriod(
        new CoreAcademicPeriodId($id),
        new AcademicPeriodCode('PERIOD-' . $id),
        new AcademicPeriodName('Period ' . $id),
        new AcademicPeriodDateRange(
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
        ),
        AcademicPeriodStatus::Inactive,
    );
}

final class E010FakeClock implements Clock
{
    public int $calls = 0;

    public function __construct(private readonly DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        ++$this->calls;

        return $this->instant;
    }
}

final class E010FakeTransactionRunner implements TransactionRunner
{
    public bool $active = false;

    /** @var list<string> */
    public array $events = [];

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new RuntimeException('Nested transaction.');
        }
        $this->active = true;
        $this->events[] = 'begin';
        try {
            $result = $operation();
            $this->events[] = 'commit';

            return $result;
        } catch (\Throwable $exception) {
            $this->events[] = 'rollback';
            throw $exception;
        } finally {
            $this->active = false;
        }
    }
}

final class E010InitializationTrace
{
    /** @var list<string> */
    public array $events = [];
}

final class E010InMemoryEnrollmentRepository implements EnrollmentRepository
{
    /** @var array<int, Enrollment> */
    private array $items = [];

    public int $saveCalls = 0;

    public int $lockCalls = 0;

    /** @var list<string> */
    public array $sequence = [];

    public ?RuntimeException $saveFailure = null;

    public bool $corruptSaveResult = false;

    public function __construct(
        private readonly E010FakeTransactionRunner $transactions,
        private readonly ?E010InitializationTrace $initializationTrace = null,
    ) {
    }

    public function findById(EnrollmentId $id): ?Enrollment
    {
        return isset($this->items[$id->value()]) ? $this->copy($this->items[$id->value()]) : null;
    }

    public function findByIdForUpdate(EnrollmentId $id): ?Enrollment
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Lock requires transaction.');
        }
        ++$this->lockCalls;
        $this->sequence[] = 'lock';

        return $this->findById($id);
    }

    public function findByStudentAndAcademicPeriod(
        EnrollmentStudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment {
        foreach ($this->items as $item) {
            if ($item->studentId()->equals($studentId)
                && $item->academicPeriodId()->equals($academicPeriodId)
            ) {
                return $this->copy($item);
            }
        }

        return null;
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        ++$this->saveCalls;
        $this->sequence[] = 'save';
        if ($this->initializationTrace !== null) {
            $this->initializationTrace->events[] = 'enrollment-save';
        }
        if ($this->saveFailure !== null) {
            throw $this->saveFailure;
        }
        $persisted = $enrollment->id() === null
            ? $this->withId($enrollment, 500 + $this->saveCalls)
            : $this->copy($enrollment);
        $id = $persisted->id()?->value() ?? throw new RuntimeException('Persisted identity missing.');
        $this->items[$id] = $this->copy($persisted);

        if ($this->corruptSaveResult) {
            return Enrollment::reconstitute(
                new EnrollmentId($id),
                $persisted->studentId(),
                new EnrollmentFamilyId(999),
                $persisted->academicPeriodId(),
                $persisted->status(),
                $persisted->academicPlacement(),
                $persisted->billingInformation(),
                $persisted->medicalInformation(),
                $persisted->transportInformation(),
                $persisted->isAuthorizedToLeaveAlone(),
                $persisted->submissionSnapshot(),
                $persisted->startedAt(),
                $persisted->submittedAt(),
                $persisted->completedAt(),
                $persisted->cancelledAt(),
            );
        }

        return $this->copy($persisted);
    }

    public function seed(Enrollment $enrollment): Enrollment
    {
        return $this->save($enrollment);
    }

    public function remove(int $id): void
    {
        unset($this->items[$id]);
    }

    public function resetObservations(): void
    {
        $this->saveCalls = 0;
        $this->lockCalls = 0;
        $this->sequence = [];
        $this->transactions->events = [];
    }

    private function withId(Enrollment $source, int $id): Enrollment
    {
        return Enrollment::reconstitute(
            new EnrollmentId($id),
            $source->studentId(),
            $source->familyId(),
            $source->academicPeriodId(),
            $source->status(),
            $source->academicPlacement(),
            $source->billingInformation(),
            $source->medicalInformation(),
            $source->transportInformation(),
            $source->isAuthorizedToLeaveAlone(),
            $source->submissionSnapshot(),
            $source->startedAt(),
            $source->submittedAt(),
            $source->completedAt(),
            $source->cancelledAt(),
        );
    }

    private function copy(Enrollment $source): Enrollment
    {
        $id = $source->id();
        if ($id === null) {
            throw new RuntimeException('Only persisted Enrollment can be copied.');
        }

        return $this->withId($source, $id->value());
    }
}

final readonly class E010StudentRepository implements StudentRepository
{
    public function __construct(private ?Student $student)
    {
    }

    public function findById(StudentId $id): ?Student
    {
        return $this->student?->id()?->equals($id) === true ? $this->student : null;
    }

    public function findByPersonId(PersonId $personId): ?Student
    {
        return null;
    }

    public function findByInstitutionalCode(InstitutionalCode $institutionalCode): ?Student
    {
        return null;
    }

    public function save(Student $student): Student
    {
        return $student;
    }
}

final class E010FamilyRepository implements FamilyRepository
{
    public int $ordinaryCalls = 0;

    public int $lockCalls = 0;

    public bool $lockObservedActiveTransaction = false;

    public function __construct(
        private readonly ?Family $family,
        private readonly E010FakeTransactionRunner $transactions,
        private readonly E010InitializationTrace $trace,
    ) {
    }

    public function findById(FamilyId $id): ?Family
    {
        return $this->family?->id()?->equals($id) === true ? $this->family : null;
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        return [];
    }

    public function findActiveByStudentId(FamilyStudentId $studentId): ?Family
    {
        ++$this->ordinaryCalls;

        return $this->family;
    }

    public function findActiveByStudentIdForUpdate(FamilyStudentId $studentId): ?Family
    {
        ++$this->lockCalls;
        $this->lockObservedActiveTransaction = $this->transactions->active;
        if (!$this->lockObservedActiveTransaction) {
            throw new RuntimeException('FamilyStudent lock requires transaction.');
        }
        $this->trace->events[] = 'family-lock';

        return $this->family;
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        \App\Family\Domain\ValueObject\RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        return $this->family?->id()?->equals($familyId) === true ? $this->family : null;
    }

    public function save(Family $family): Family
    {
        return $family;
    }
}

final readonly class E010AcademicPeriodRepository implements AcademicPeriodRepository
{
    public function __construct(private ?AcademicPeriod $period)
    {
    }

    public function findById(CoreAcademicPeriodId $id): ?AcademicPeriod
    {
        return $this->period?->id()?->equals($id) === true ? $this->period : null;
    }

    public function findActive(): ?AcademicPeriod
    {
        return null;
    }

    public function save(AcademicPeriod $period): AcademicPeriod
    {
        return $period;
    }

    public function lockOperationalTransition(): void
    {
    }

    public function lockActiveContextForRead(): void
    {
    }
}

final class E010AcademicReferences implements AcademicPlacementReferenceProvider
{
    /** @var array<int, AcademicGradeReference> */
    public array $grades = [];

    /** @var array<int, AcademicSectionReference> */
    public array $sections = [];

    public function findGradeById(int $gradeId): ?AcademicGradeReference
    {
        return $this->grades[$gradeId] ?? null;
    }

    public function findSectionById(int $sectionId): ?AcademicSectionReference
    {
        return $this->sections[$sectionId] ?? null;
    }

    public function findNextActiveGradeAfterSortOrder(int $sortOrder): ?AcademicGradeReference
    {
        $matches = array_values(array_filter(
            $this->grades,
            static fn (AcademicGradeReference $grade): bool =>
                $grade->status === 'ACTIVE' && $grade->sortOrder > $sortOrder,
        ));
        usort($matches, static fn ($left, $right): int => $left->sortOrder <=> $right->sortOrder);

        return $matches[0] ?? null;
    }
}
