<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\AcademicPeriodCodeLookup;
use App\AcademicCore\Application\AcademicPlacementCodeReferenceProvider;
use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicSectionReference;
use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;
use App\Enrollment\Application\AcademicInitialization\AcademicInitializationPreflight;
use App\Enrollment\Application\AcademicInitialization\AcademicInitializationStateClassifier;
use App\Enrollment\Application\AcademicInitialization\ApplyAcademicInitialization;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationClassification;
use App\Enrollment\Application\AcademicInitialization\Exception\AcademicInitializationRejected;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId as EnrollmentFamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Enrollment\Infrastructure\Csv\CsvAcademicInitializationManifestReader;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyCode;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId as FamilyStudentMembershipId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\Contract\Clock;
use App\Student\Application\LockingStudentRepository;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;
use Throwable;

function registerAcademicInitializationApplicationTests(TestRunner $runner): void
{
    $runner->add('Post-import academic initialization CSV enforces exact bounded safe contract', function (): void {
        $reader = new CsvAcademicInitializationManifestReader();
        $valid = academicInitializationManifest([
            ['STU-001', 'EGB_1', 'A'],
            ['STU-002', 'BGU_3', 'A'],
        ], bom: true);
        try {
            $manifest = $reader->read($valid);
            assertSameValue(true, $manifest->isValid());
            assertSameValue(['STU-001', 'BGU_3'], [
                $manifest->rows[0]->institutionalCode,
                $manifest->rows[1]->gradeCode,
            ]);
            assertSameValue([2, 3], array_column($manifest->rows, 'sourceRow'));
        } finally {
            @unlink($valid);
        }

        foreach ([
            "wrong,grade_code,section_code\nSTU-001,EGB_1,A\n",
            "institutional_code,grade_code,section_code\nSTU-001,EGB_1,A\nSTU-001,EGB_2,A\n",
            "institutional_code,grade_code,section_code\n=FORMULA,EGB_1,A\n",
            "institutional_code,grade_code,section_code\nSTU-001,EGB 1,A\n",
            "institutional_code,grade_code,section_code\n,,\n",
        ] as $invalidCsv) {
            $path = academicInitializationRawManifest($invalidCsv);
            try {
                assertSameValue(false, $reader->read($path)->isValid(), $invalidCsv);
            } finally {
                @unlink($path);
            }
        }
    });

    $runner->add('Post-import preflight classifies create set and no-op without writes', function (): void {
        $environment = academicInitializationEnvironment(3);
        $environment->enrollments->seed(academicInitializationEnrollment(2));
        $environment->enrollments->seed(academicInitializationEnrollment(3, placement: true));
        $environment->enrollments->resetObservations();
        $manifest = academicInitializationManifest([
            ['STU-001', 'EGB_1', 'A'],
            ['STU-002', 'EGB_1', 'A'],
            ['STU-003', 'EGB_1', 'A'],
        ]);

        try {
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            assertSameValue(true, $preview->isApplicable());
            assertSameValue(
                ['CREATE_DRAFT', 'SET_PLACEMENT', 'ALREADY_CORRECT'],
                array_map(static fn ($item): string => $item->classification->value, $preview->items),
            );
            assertSameValue(0, $environment->enrollments->saveCalls);
            assertSameValue(64, strlen($preview->stateDigest()));
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import preflight rejects missing references context lifecycle and differing placement', function (): void {
        $manifest = academicInitializationManifest([['STU-001', 'EGB_1', 'A']]);
        try {
            foreach (['student', 'family', 'period', 'grade', 'section'] as $missing) {
                $environment = academicInitializationEnvironment();
                $environment->remove($missing);
                $preview = $environment->preflight->handle($manifest, '2026-2027');
                assertSameValue(false, $preview->isApplicable(), $missing);
                assertSameValue(true, $preview->issues !== [], $missing);
                assertSameValue(0, $environment->enrollments->saveCalls, $missing);
            }

            foreach ([
                EnrollmentStatus::Submitted,
                EnrollmentStatus::Completed,
                EnrollmentStatus::Cancelled,
            ] as $status) {
                $environment = academicInitializationEnvironment();
                $environment->enrollments->seed(academicInitializationEnrollment(1, status: $status));
                $environment->enrollments->resetObservations();
                $preview = $environment->preflight->handle($manifest, '2026-2027');
                assertSameValue(false, $preview->isApplicable(), $status->value);
                assertSameValue(
                    AcademicInitializationClassification::Conflict,
                    $preview->items[0]->classification,
                );
                assertSameValue(0, $environment->enrollments->saveCalls);
            }

            $environment = academicInitializationEnvironment();
            $environment->enrollments->seed(academicInitializationEnrollment(
                1,
                placement: true,
                gradeId: 11,
            ));
            $environment->enrollments->resetObservations();
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            assertSameValue(false, $preview->isApplicable());
            assertSameValue('CONFLICT', $preview->items[0]->classification->value);
            assertSameValue(0, $environment->enrollments->saveCalls);
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import preflight rejects distinct manifest codes that resolve to one Student', function (): void {
        $environment = academicInitializationEnvironment();
        $environment->students->alias('STU-ALIAS', 'STU-001');
        $manifest = academicInitializationManifest([
            ['STU-001', 'EGB_1', 'A'],
            ['STU-ALIAS', 'EGB_1', 'A'],
        ]);

        try {
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            assertSameValue(false, $preview->isApplicable());
            assertSameValue('STUDENT_IDENTITY_AMBIGUOUS', $preview->issues[0]->category);
            assertSameValue(0, $environment->enrollments->saveCalls);
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import Apply is deterministic atomic and idempotent for the complete valid batch', function (): void {
        $environment = academicInitializationEnvironment(3);
        $environment->enrollments->seed(academicInitializationEnrollment(2));
        $environment->enrollments->seed(academicInitializationEnrollment(3, placement: true));
        $environment->enrollments->resetObservations();
        $manifest = academicInitializationManifest([
            ['STU-003', 'EGB_1', 'A'],
            ['STU-001', 'EGB_1', 'A'],
            ['STU-002', 'EGB_1', 'A'],
        ]);

        try {
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            $result = $environment->apply->handle($manifest, '2026-2027', $preview->stateDigest());
            assertSameValue([3, 1, 1, 1], [
                $result->rows,
                $result->createdDrafts,
                $result->placementsSet,
                $result->alreadyCorrect,
            ]);
            assertSameValue(['STU-001', 'STU-002', 'STU-003'], $environment->students->lockOrder);
            assertSameValue(['begin', 'commit'], $environment->transactions->events);
            foreach ([1, 2, 3] as $studentId) {
                $enrollment = $environment->enrollments->byStudent($studentId);
                assertSameValue('DRAFT', $enrollment?->status()->value);
                assertSameValue([10, 20], [
                    $enrollment?->academicPlacement()?->gradeId()->value(),
                    $enrollment?->academicPlacement()?->sectionId()?->value(),
                ]);
            }

            $environment->students->lockOrder = [];
            $environment->transactions->events = [];
            $secondPreview = $environment->preflight->handle($manifest, '2026-2027');
            $second = $environment->apply->handle(
                $manifest,
                '2026-2027',
                $secondPreview->stateDigest(),
            );
            assertSameValue([0, 0, 3], [
                $second->createdDrafts,
                $second->placementsSet,
                $second->alreadyCorrect,
            ]);
            assertSameValue(3, $environment->enrollments->count());
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import Apply rejects stale preview before writes', function (): void {
        $environment = academicInitializationEnvironment();
        $manifest = academicInitializationManifest([['STU-001', 'EGB_1', 'A']]);
        try {
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            $environment->enrollments->seed(academicInitializationEnrollment(
                1,
                placement: true,
                gradeId: 11,
            ));
            $environment->enrollments->resetObservations();
            assertThrows(
                static fn () => $environment->apply->handle(
                    $manifest,
                    '2026-2027',
                    $preview->stateDigest(),
                ),
                AcademicInitializationRejected::class,
            );
            assertSameValue(0, $environment->enrollments->saveCalls);
            assertSameValue(11, $environment->enrollments->byStudent(1)?->academicPlacement()?->gradeId()->value());
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import Apply rolls back every earlier row when persistence fails', function (): void {
        $environment = academicInitializationEnvironment(2);
        $environment->enrollments->failOnSaveCall = 2;
        $manifest = academicInitializationManifest([
            ['STU-001', 'EGB_1', 'A'],
            ['STU-002', 'EGB_1', 'A'],
        ]);
        try {
            $preview = $environment->preflight->handle($manifest, '2026-2027');
            assertThrows(
                static fn () => $environment->apply->handle(
                    $manifest,
                    '2026-2027',
                    $preview->stateDigest(),
                ),
                RuntimeException::class,
            );
            assertSameValue(0, $environment->enrollments->count());
            assertSameValue(['begin', 'rollback'], $environment->transactions->events);
        } finally {
            @unlink($manifest);
        }
    });

    $runner->add('Post-import Academic Core code lookups resolve exact active catalog references', function (): void {
        $manager = familySqliteManager();
        $pdo = $manager->connection();
        $pdo->exec(
            'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
            . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
            . 'CREATE TABLE academic_periods (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, '
            . 'starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, status_id INTEGER NOT NULL);'
            . 'CREATE TABLE grades (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, '
            . 'sort_order INTEGER NOT NULL, status_id INTEGER NOT NULL);'
            . 'CREATE TABLE sections (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, '
            . 'status_id INTEGER NOT NULL);'
            . "INSERT INTO status_types VALUES (1, 'GENERAL_STATUS');"
            . "INSERT INTO statuses VALUES (1, 1, 'ACTIVE');"
            . "INSERT INTO academic_periods VALUES (5, '2026-2027', 'Period', '2026-09-01', '2027-07-13', 1);"
            . "INSERT INTO grades VALUES (10, 'EGB_1', 'Grade', 1, 1);"
            . "INSERT INTO sections VALUES (20, 'A', 'A', 1);"
        );
        $periods = new \App\AcademicCore\Infrastructure\Persistence\PdoAcademicPeriodRepository($manager);
        $references = new \App\AcademicCore\Infrastructure\Persistence\PdoAcademicPlacementReferenceProvider($manager);

        assertSameValue(5, $periods->findByCode(new AcademicPeriodCode('2026-2027'))?->id()?->value());
        assertSameValue(10, $references->findGradeByCode('EGB_1')?->id);
        assertSameValue(20, $references->findSectionByCode('A')?->id);
        assertSameValue(null, $periods->findByCode(new AcademicPeriodCode('MISSING')));
        assertSameValue(null, $references->findGradeByCode('MISSING'));
    });
}

final class AcademicInitializationEnvironment
{
    public readonly AcademicInitializationStudentRepository $students;
    public readonly AcademicInitializationFamilyRepository $families;
    public readonly AcademicInitializationPeriodRepository $periods;
    public readonly AcademicInitializationReferences $references;
    public readonly AcademicInitializationEnrollmentRepository $enrollments;
    public readonly AcademicInitializationTransactionRunner $transactions;
    public readonly AcademicInitializationPreflight $preflight;
    public readonly ApplyAcademicInitialization $apply;

    public function __construct(int $studentCount)
    {
        $this->transactions = new AcademicInitializationTransactionRunner();
        $this->students = new AcademicInitializationStudentRepository($this->transactions);
        $this->families = new AcademicInitializationFamilyRepository($this->transactions);
        $this->periods = new AcademicInitializationPeriodRepository($this->transactions);
        $this->references = new AcademicInitializationReferences();
        $this->enrollments = new AcademicInitializationEnrollmentRepository($this->transactions);
        $this->transactions->repository = $this->enrollments;
        for ($id = 1; $id <= $studentCount; ++$id) {
            $this->students->add(academicInitializationStudent($id));
            $this->families->add($id, academicInitializationFamily($id));
        }
        $classifier = new AcademicInitializationStateClassifier();
        $this->preflight = new AcademicInitializationPreflight(
            new CsvAcademicInitializationManifestReader(),
            $this->students,
            $this->families,
            $this->periods,
            $this->references,
            $this->enrollments,
            $classifier,
        );
        $initializer = new EnrollmentDraftInitializer(
            $this->students,
            $this->families,
            $this->periods,
            $this->enrollments,
            $this->references,
            new AcademicInitializationClock(),
        );
        $this->apply = new ApplyAcademicInitialization(
            $this->preflight,
            $this->students,
            $this->families,
            $this->periods,
            $this->periods,
            $this->references,
            $this->enrollments,
            $initializer,
            $classifier,
            $this->transactions,
        );
    }

    public function remove(string $reference): void
    {
        match ($reference) {
            'student' => $this->students->clear(),
            'family' => $this->families->clear(),
            'period' => $this->periods->period = null,
            'grade' => $this->references->gradesByCode = [],
            'section' => $this->references->sectionsByCode = [],
            default => throw new RuntimeException('Unknown fixture reference.'),
        };
    }
}

function academicInitializationEnvironment(int $studentCount = 1): AcademicInitializationEnvironment
{
    return new AcademicInitializationEnvironment($studentCount);
}

final class AcademicInitializationTransactionRunner implements TransactionRunner
{
    public bool $active = false;
    public ?AcademicInitializationEnrollmentRepository $repository = null;
    /** @var list<string> */
    public array $events = [];

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new RuntimeException('Nested transaction.');
        }
        $snapshot = $this->repository?->snapshot() ?? [];
        $this->active = true;
        $this->events[] = 'begin';
        try {
            $result = $operation();
            $this->events[] = 'commit';

            return $result;
        } catch (Throwable $exception) {
            $this->repository?->restore($snapshot);
            $this->events[] = 'rollback';
            throw $exception;
        } finally {
            $this->active = false;
        }
    }
}

final class AcademicInitializationStudentRepository implements LockingStudentRepository
{
    /** @var array<string, Student> */
    private array $students = [];
    /** @var list<string> */
    public array $lockOrder = [];

    public function __construct(private readonly AcademicInitializationTransactionRunner $transactions)
    {
    }

    public function add(Student $student): void
    {
        $this->students[$student->institutionalCode()->value()] = $student;
    }

    public function clear(): void
    {
        $this->students = [];
    }

    public function alias(string $alias, string $existingCode): void
    {
        if (!isset($this->students[$existingCode])) {
            throw new RuntimeException('Unknown Student fixture code.');
        }
        $this->students[$alias] = $this->students[$existingCode];
    }

    public function findById(StudentId $id): ?Student
    {
        foreach ($this->students as $student) {
            if ($student->id()?->equals($id) === true) {
                return $student;
            }
        }

        return null;
    }

    public function findByIdForUpdate(StudentId $id): ?Student
    {
        return $this->findById($id);
    }

    public function findByPersonId(PersonId $personId): ?Student
    {
        return null;
    }

    public function findByPersonIdForUpdate(PersonId $personId): ?Student
    {
        return null;
    }

    public function findByInstitutionalCode(InstitutionalCode $institutionalCode): ?Student
    {
        return $this->students[$institutionalCode->value()] ?? null;
    }

    public function findByInstitutionalCodeForUpdate(InstitutionalCode $institutionalCode): ?Student
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Student lock requires transaction.');
        }
        $this->lockOrder[] = $institutionalCode->value();

        return $this->findByInstitutionalCode($institutionalCode);
    }

    public function save(Student $student): Student
    {
        return $student;
    }
}

final class AcademicInitializationFamilyRepository implements FamilyRepository
{
    /** @var array<int, Family> */
    private array $families = [];

    public function __construct(private readonly AcademicInitializationTransactionRunner $transactions)
    {
    }

    public function add(int $studentId, Family $family): void
    {
        $this->families[$studentId] = $family;
    }

    public function clear(): void
    {
        $this->families = [];
    }

    public function findById(FamilyId $id): ?Family
    {
        foreach ($this->families as $family) {
            if ($family->id()?->equals($id) === true) {
                return $family;
            }
        }

        return null;
    }

    public function findByIdForUpdate(FamilyId $id): ?Family
    {
        return $this->findById($id);
    }

    public function findByCode(FamilyCode $familyCode): ?Family
    {
        return null;
    }

    public function findByCodeForUpdate(FamilyCode $familyCode): ?Family
    {
        return null;
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        return [];
    }

    public function findActiveByRepresentativeAndFamilyForUpdate(
        RepresentativeId $representativeId,
        FamilyId $familyId,
    ): ?Family {
        return null;
    }

    public function findActiveByStudentId(FamilyStudentId $studentId): ?Family
    {
        return $this->families[$studentId->value()] ?? null;
    }

    public function findActiveByStudentIdForUpdate(FamilyStudentId $studentId): ?Family
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Family lock requires transaction.');
        }

        return $this->findActiveByStudentId($studentId);
    }

    public function save(Family $family): Family
    {
        return $family;
    }
}

final class AcademicInitializationPeriodRepository implements AcademicPeriodRepository, AcademicPeriodCodeLookup
{
    public ?AcademicPeriod $period;

    public function __construct(private readonly AcademicInitializationTransactionRunner $transactions)
    {
        $this->period = new AcademicPeriod(
            new CoreAcademicPeriodId(40),
            new AcademicPeriodCode('2026-2027'),
            new AcademicPeriodName('Año lectivo 2026–2027'),
            new AcademicPeriodDateRange(
                new DateTimeImmutable('2026-09-01'),
                new DateTimeImmutable('2027-07-13'),
            ),
            AcademicPeriodStatus::Active,
        );
    }

    public function findById(CoreAcademicPeriodId $id): ?AcademicPeriod
    {
        return $this->period?->id()?->equals($id) === true ? $this->period : null;
    }

    public function findByCode(AcademicPeriodCode $code): ?AcademicPeriod
    {
        return $this->period?->code()->equals($code) === true ? $this->period : null;
    }

    public function findActive(): ?AcademicPeriod
    {
        return $this->period?->isActive() === true ? $this->period : null;
    }

    public function save(AcademicPeriod $period): AcademicPeriod
    {
        $this->period = $period;

        return $period;
    }

    public function lockOperationalTransition(): void
    {
    }

    public function lockActiveContextForRead(): void
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('AcademicPeriod lock requires transaction.');
        }
    }
}

final class AcademicInitializationReferences implements
    AcademicPlacementReferenceProvider,
    AcademicPlacementCodeReferenceProvider
{
    /** @var array<string, AcademicGradeReference> */
    public array $gradesByCode = [];
    /** @var array<string, AcademicSectionReference> */
    public array $sectionsByCode = [];

    public function __construct()
    {
        $this->gradesByCode['EGB_1'] = new AcademicGradeReference(10, 'EGB_1', '1.º EGB', 'ACTIVE', 1);
        $this->gradesByCode['EGB_2'] = new AcademicGradeReference(11, 'EGB_2', '2.º EGB', 'ACTIVE', 2);
        $this->sectionsByCode['A'] = new AcademicSectionReference(20, 'A', 'A', 'ACTIVE');
    }

    public function findGradeById(int $gradeId): ?AcademicGradeReference
    {
        foreach ($this->gradesByCode as $grade) {
            if ($grade->id === $gradeId) {
                return $grade;
            }
        }

        return null;
    }

    public function findSectionById(int $sectionId): ?AcademicSectionReference
    {
        foreach ($this->sectionsByCode as $section) {
            if ($section->id === $sectionId) {
                return $section;
            }
        }

        return null;
    }

    public function findNextActiveGradeAfterSortOrder(int $sortOrder): ?AcademicGradeReference
    {
        return null;
    }

    public function findGradeByCode(string $gradeCode): ?AcademicGradeReference
    {
        return $this->gradesByCode[$gradeCode] ?? null;
    }

    public function findSectionByCode(string $sectionCode): ?AcademicSectionReference
    {
        return $this->sectionsByCode[$sectionCode] ?? null;
    }
}

final class AcademicInitializationEnrollmentRepository implements EnrollmentRepository
{
    /** @var array<int, Enrollment> */
    private array $items = [];
    public int $saveCalls = 0;
    public ?int $failOnSaveCall = null;
    private int $nextId = 500;

    public function __construct(private readonly AcademicInitializationTransactionRunner $transactions)
    {
    }

    public function findById(EnrollmentId $id): ?Enrollment
    {
        return isset($this->items[$id->value()]) ? $this->copy($this->items[$id->value()]) : null;
    }

    public function findByIdForUpdate(EnrollmentId $id): ?Enrollment
    {
        if (!$this->transactions->active) {
            throw new RuntimeException('Enrollment lock requires transaction.');
        }

        return $this->findById($id);
    }

    public function findByStudentAndAcademicPeriod(
        EnrollmentStudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment {
        foreach ($this->items as $enrollment) {
            if ($enrollment->studentId()->equals($studentId)
                && $enrollment->academicPeriodId()->equals($academicPeriodId)
            ) {
                return $this->copy($enrollment);
            }
        }

        return null;
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        ++$this->saveCalls;
        if ($this->failOnSaveCall === $this->saveCalls) {
            throw new RuntimeException('Injected persistence failure.');
        }
        if ($enrollment->id() === null) {
            foreach ($this->items as $existing) {
                if ($existing->studentId()->equals($enrollment->studentId())
                    && $existing->academicPeriodId()->equals($enrollment->academicPeriodId())
                ) {
                    throw new RuntimeException('Student AcademicPeriod unique conflict.');
                }
            }
            $enrollment = $this->withId($enrollment, $this->nextId++);
        }
        $id = $enrollment->id()?->value() ?? throw new RuntimeException('Enrollment identity missing.');
        $this->items[$id] = $this->copy($enrollment);

        return $this->copy($enrollment);
    }

    public function seed(Enrollment $enrollment): void
    {
        $this->save($enrollment);
    }

    public function resetObservations(): void
    {
        $this->saveCalls = 0;
    }

    public function byStudent(int $studentId): ?Enrollment
    {
        return $this->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId($studentId),
            new AcademicPeriodId(40),
        );
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return array{items: array<int, Enrollment>, next_id: int} */
    public function snapshot(): array
    {
        $items = [];
        foreach ($this->items as $id => $enrollment) {
            $items[$id] = $this->copy($enrollment);
        }

        return ['items' => $items, 'next_id' => $this->nextId];
    }

    /** @param array{items?: array<int, Enrollment>, next_id?: int} $snapshot */
    public function restore(array $snapshot): void
    {
        $this->items = $snapshot['items'] ?? [];
        $this->nextId = $snapshot['next_id'] ?? 500;
    }

    private function copy(Enrollment $enrollment): Enrollment
    {
        $id = $enrollment->id() ?? throw new RuntimeException('Only persisted Enrollment can be copied.');

        return $this->withId($enrollment, $id->value());
    }

    private function withId(Enrollment $enrollment, int $id): Enrollment
    {
        return Enrollment::reconstitute(
            new EnrollmentId($id),
            $enrollment->studentId(),
            $enrollment->familyId(),
            $enrollment->academicPeriodId(),
            $enrollment->status(),
            $enrollment->academicPlacement(),
            $enrollment->billingInformation(),
            $enrollment->medicalInformation(),
            $enrollment->transportInformation(),
            $enrollment->isAuthorizedToLeaveAlone(),
            $enrollment->startedAt(),
            $enrollment->submittedAt(),
            $enrollment->completedAt(),
            $enrollment->cancelledAt(),
        );
    }
}

final readonly class AcademicInitializationClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01 12:00:00+00:00');
    }
}

function academicInitializationStudent(int $id): Student
{
    return new Student(
        new StudentId($id),
        new PersonId(100 + $id),
        new InstitutionalCode(sprintf('STU-%03d', $id)),
        new AdmissionDate(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-09-01')),
        StudentStatus::Active,
    );
}

function academicInitializationFamily(int $studentId): Family
{
    return Family::reconstitute(
        new FamilyId(1000 + $studentId),
        new FamilyCode(sprintf('F%08d', $studentId)),
        new DisplayName('Family ' . $studentId),
        FamilyStatus::Active,
        [new FamilyRepresentative(
            new FamilyRepresentativeId(2000 + $studentId),
            new RepresentativeId(3000 + $studentId),
            new RelationshipTypeId(1),
            true,
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            null,
        )],
        [new FamilyStudent(
            new FamilyStudentMembershipId(4000 + $studentId),
            new FamilyStudentId($studentId),
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            null,
        )],
    );
}

function academicInitializationEnrollment(
    int $studentId,
    bool $placement = false,
    int $gradeId = 10,
    EnrollmentStatus $status = EnrollmentStatus::Draft,
): Enrollment {
    $enrollment = Enrollment::startDraft(
        new EnrollmentStudentId($studentId),
        new EnrollmentFamilyId(1000 + $studentId),
        new AcademicPeriodId(40),
        new DateTimeImmutable('2026-09-01 12:00:00+00:00'),
        $placement ? new AcademicPlacement(new GradeId($gradeId), new SectionId(20)) : null,
    );
    if ($status === EnrollmentStatus::Submitted) {
        $enrollment->submit(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
    } elseif ($status === EnrollmentStatus::Completed) {
        $enrollment->submit(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
        $enrollment->complete(new DateTimeImmutable('2026-09-03 12:00:00+00:00'));
    } elseif ($status === EnrollmentStatus::Cancelled) {
        $enrollment->cancel(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
    }

    return $enrollment;
}

/** @param list<array{string, string, string}> $rows */
function academicInitializationManifest(array $rows, bool $bom = false): string
{
    $content = ($bom ? "\xEF\xBB\xBF" : '') . "institutional_code,grade_code,section_code\n";
    foreach ($rows as $row) {
        $content .= implode(',', $row) . "\n";
    }

    return academicInitializationRawManifest($content);
}

function academicInitializationRawManifest(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'antares-academic-init-');
    if (!is_string($path) || file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to create academic initialization fixture.');
    }

    return $path;
}
