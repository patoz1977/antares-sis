<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\StudentAlreadyHasActiveFamily;
use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Application\Orchestration\Dto\RepresentativeFamilyOutput;
use App\Family\Application\Orchestration\Dto\StudentFamilyOutput;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\FamilyStatus;
use App\Person\Application\CreatePerson;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Domain\Exception\InvalidRepresentativeState;
use App\Representative\Domain\RepresentativeStatus;
use App\Student\Application\CreateStudent;
use App\Student\Application\Exception\InstitutionalCodeAlreadyUsed;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Domain\Exception\InvalidStudentState;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use RuntimeException;
use Tests\Support\TestRunner;
use Throwable;

function registerFamilyCompositeOrchestrationTests(TestRunner $runner): void
{
    $runner->add('CreateRepresentativeFamily commits the complete approved flow', function (): void {
        $environment = new CompositeOrchestrationEnvironment();
        $input = compositeRepresentativeInput();
        $output = $environment->representativeFlow()->handle($input, $environment->today);

        assertComposite($output instanceof RepresentativeFamilyOutput, 'Unexpected composite output.');
        assertComposite($output->person->id > 0, 'Person identity was not generated.');
        assertComposite($output->representative->id > 0, 'Representative identity was not generated.');
        assertComposite($output->family->id > 0, 'Family identity was not generated.');
        assertComposite(
            $output->representative->personId === $output->person->id,
            'Representative does not reference the generated Person.'
        );
        $primary = array_values(array_filter(
            $output->family->representatives,
            static fn ($membership): bool => $membership->isPrimary && $membership->isActive,
        ));
        assertComposite(count($primary) === 1, 'Family lacks one active primary Representative.');
        assertComposite(
            $primary[0]->representativeId === $output->representative->id,
            'Family primary membership does not reference the generated Representative.'
        );
        assertComposite($primary[0]->relationshipTypeId === 11, 'RelationshipType was not preserved.');
        assertComposite(
            $primary[0]->startedAt->format('Y-m-d H:i:s.u P') === '2026-08-05 10:11:12.000000 -05:00',
            'Explicit membership timestamp did not preserve seconds and timezone.'
        );
        assertComposite($output->person->status === PersonStatus::Inactive, 'Person status changed.');
        assertComposite(
            $output->representative->status === RepresentativeStatus::Active,
            'Representative status changed.'
        );
        assertComposite($output->family->status === FamilyStatus::Inactive, 'Family status changed.');
        assertComposite($output->family->students === [], 'Representative flow created a Student.');
        assertComposite($environment->students->saveCalls() === 0, 'Representative flow saved Student.');
        assertCompositeTransactionCommitted($environment->transactions);
    });

    $runner->add('CreateRepresentativeFamily rolls back every approved failure stage', function (): void {
        $scenarios = [
            'invalid Person' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(['birthDate' => compositeDate('2026-08-06')]),
                    InvalidPersonState::class,
                    null,
                ];
            },
            'duplicate identification' => static function (CompositeOrchestrationEnvironment $environment): array {
                $environment->persons->seed(compositePersonFixture(77, 'COMPOSITE-REP-001'));

                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(),
                    IdentificationAlreadyUsed::class,
                    null,
                ];
            },
            'invalid Representative' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(['workEmail' => 'invalid-email']),
                    InvalidRepresentativeState::class,
                    null,
                ];
            },
            'invalid persisted Representative' => static function (CompositeOrchestrationEnvironment $environment): array {
                $environment->representatives->returnWithoutId();

                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(),
                    InvalidPersistedRepresentativeResult::class,
                    null,
                ];
            },
            'missing RelationshipType' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->representativeFlow(new FakeRelationshipTypeLookup([])),
                    compositeRepresentativeInput(),
                    RelationshipTypeNotFound::class,
                    null,
                ];
            },
            'invalid Family' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(['displayName' => '   ']),
                    InvalidFamilyState::class,
                    null,
                ];
            },
            'invalid persisted Family' => static function (CompositeOrchestrationEnvironment $environment): array {
                $environment->families->returnWithoutFamilyId();

                return [
                    $environment->representativeFlow(),
                    compositeRepresentativeInput(),
                    InvalidPersistedFamilyResult::class,
                    null,
                ];
            },
            'simulated physical Family restriction' => static function (
                CompositeOrchestrationEnvironment $environment,
            ): array {
                $failure = new RuntimeException('simulated physical Family restriction');
                $repository = new ThrowAfterFamilySaveRepository($environment->families, $failure);

                return [
                    $environment->representativeFlow(families: $repository),
                    compositeRepresentativeInput(),
                    RuntimeException::class,
                    $failure,
                ];
            },
        ];

        foreach ($scenarios as $label => $configure) {
            $environment = new CompositeOrchestrationEnvironment();
            [$flow, $input, $expectedClass, $expectedInstance] = $configure($environment);
            $before = compositeRepositoryState($environment);
            $caught = compositeCaught(
                static fn () => $flow->handle($input, $environment->today)
            );

            assertComposite(
                $caught instanceof $expectedClass,
                sprintf('%s propagated %s.', $label, get_debug_type($caught)),
            );
            if ($expectedInstance !== null) {
                assertComposite($caught === $expectedInstance, $label . ' replaced the original exception.');
            }
            assertCompositeRollback($environment, $before, $label);
        }
    });

    $runner->add('CreateStudentInFamily commits Student and preserves Family history', function (): void {
        $environment = new CompositeOrchestrationEnvironment();
        $input = compositeStudentInput($environment->familyId);
        $output = $environment->studentFlow()->handle($input, $environment->today);

        assertComposite($output instanceof StudentFamilyOutput, 'Unexpected Student composite output.');
        assertComposite($output->person->id > 0, 'Student Person identity was not generated.');
        assertComposite($output->student->id > 0, 'Student identity was not generated.');
        assertComposite($output->student->personId === $output->person->id, 'Student Person link differs.');
        assertComposite($output->family->id === $environment->familyId, 'Existing Family changed.');
        $active = array_values(array_filter(
            $output->family->students,
            static fn ($membership): bool =>
                $membership->studentId === $output->student->id && $membership->isActive,
        ));
        assertComposite(count($active) === 1 && $active[0]->id > 0, 'Active Student membership lacks ID.');
        assertComposite(
            $active[0]->startedAt->format('Y-m-d H:i:s.u P') === '2026-08-05 18:19:20.000000 +02:00',
            'Student startedAt did not preserve seconds and timezone.'
        );
        assertComposite(count($output->family->students) === 2, 'Historical membership was lost.');
        assertComposite(
            count(array_filter(
                $output->family->students,
                static fn ($membership): bool => !$membership->isActive && $membership->studentId === 41,
            )) === 1,
            'Ended Student history changed.'
        );
        assertComposite($output->person->status === PersonStatus::Active, 'Person status changed.');
        assertComposite($output->student->status === StudentStatus::Inactive, 'Student status changed.');
        assertComposite($environment->representatives->saveCalls() === 0, 'Student flow saved Representative.');
        assertCompositeTransactionCommitted($environment->transactions);
    });

    $runner->add('CreateStudentInFamily checks Family before creating Person', function (): void {
        $environment = new CompositeOrchestrationEnvironment();
        $before = compositeRepositoryState($environment);
        $caught = compositeCaught(fn () => $environment->studentFlow()->handle(
            compositeStudentInput(999999),
            $environment->today,
        ));

        assertComposite($caught instanceof FamilyNotFound, 'Missing Family did not propagate FamilyNotFound.');
        assertCompositeRollback($environment, $before, 'missing Family');
    });

    $runner->add('CreateStudentInFamily rolls back every approved failure stage', function (): void {
        $scenarios = [
            'invalid Person' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId, [
                        'birthDate' => compositeDate('2026-08-06'),
                    ]),
                    InvalidPersonState::class,
                    null,
                ];
            },
            'duplicate identification' => static function (CompositeOrchestrationEnvironment $environment): array {
                $environment->persons->seed(compositePersonFixture(78, 'COMPOSITE-STUDENT-PERSON-001'));

                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId),
                    IdentificationAlreadyUsed::class,
                    null,
                ];
            },
            'invalid Student' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId, ['institutionalCode' => '   ']),
                    InvalidStudentState::class,
                    null,
                ];
            },
            'duplicate institutional code' => static function (
                CompositeOrchestrationEnvironment $environment,
            ): array {
                $environment->students->seed(compositeStudentFixture(
                    79,
                    80,
                    'COMPOSITE-STUDENT-001',
                ));

                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId),
                    InstitutionalCodeAlreadyUsed::class,
                    null,
                ];
            },
            'future admission date' => static function (CompositeOrchestrationEnvironment $environment): array {
                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId, [
                        'admissionDate' => compositeDate('2026-08-06'),
                    ]),
                    InvalidStudentState::class,
                    null,
                ];
            },
            'invalid persisted Student' => static function (CompositeOrchestrationEnvironment $environment): array {
                $environment->students->returnWithoutId();

                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId),
                    InvalidPersistedStudentResult::class,
                    null,
                ];
            },
            'Student already has active Family' => static function (
                CompositeOrchestrationEnvironment $environment,
            ): array {
                $repository = new AlwaysActiveStudentFamilyRepository(
                    $environment->families,
                    $environment->familyId,
                );

                return [
                    $environment->studentFlow($repository),
                    compositeStudentInput($environment->familyId),
                    StudentAlreadyHasActiveFamily::class,
                    null,
                ];
            },
            'invalid persisted FamilyStudent' => static function (
                CompositeOrchestrationEnvironment $environment,
            ): array {
                $environment->families->returnWithoutNewStudentMembershipId();

                return [
                    $environment->studentFlow(),
                    compositeStudentInput($environment->familyId),
                    InvalidPersistedFamilyResult::class,
                    null,
                ];
            },
            'simulated physical FamilyStudent restriction' => static function (
                CompositeOrchestrationEnvironment $environment,
            ): array {
                $failure = new RuntimeException('simulated physical FamilyStudent restriction');
                $repository = new ThrowAfterFamilySaveRepository($environment->families, $failure);

                return [
                    $environment->studentFlow($repository),
                    compositeStudentInput($environment->familyId),
                    RuntimeException::class,
                    $failure,
                ];
            },
        ];

        foreach ($scenarios as $label => $configure) {
            $environment = new CompositeOrchestrationEnvironment();
            [$flow, $input, $expectedClass, $expectedInstance] = $configure($environment);
            $before = compositeRepositoryState($environment);
            $caught = compositeCaught(
                static fn () => $flow->handle($input, $environment->today)
            );

            assertComposite(
                $caught instanceof $expectedClass,
                sprintf('%s propagated %s.', $label, get_debug_type($caught)),
            );
            if ($expectedInstance !== null) {
                assertComposite($caught === $expectedInstance, $label . ' replaced the original exception.');
            }
            assertCompositeRollback($environment, $before, $label);
        }
    });

    $runner->add('Family composite orchestration depends only on cases and TransactionRunner', function (): void {
        $representativeDependencies = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            (new ReflectionClass(CreateRepresentativeFamily::class))->getConstructor()?->getParameters() ?? [],
        );
        $studentDependencies = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            (new ReflectionClass(CreateStudentInFamily::class))->getConstructor()?->getParameters() ?? [],
        );
        assertComposite($representativeDependencies === [
            TransactionRunner::class,
            CreatePerson::class,
            CreateRepresentative::class,
            CreateFamily::class,
        ], 'Representative orchestration dependencies changed.');
        assertComposite($studentDependencies === [
            TransactionRunner::class,
            GetFamily::class,
            CreatePerson::class,
            CreateStudent::class,
            AddStudentToFamily::class,
        ], 'Student orchestration dependencies changed.');

        $source = compositeOrchestrationSource();
        foreach ([
            'PDO',
            'ConnectionManager',
            '\\Infrastructure\\',
            'Repository',
            '\\Http\\',
            'Controller',
            'Session',
            '\\Views\\',
            'SELECT ',
            'INSERT ',
            'UPDATE ',
            'DELETE ',
            'new DateTimeImmutable',
            'RepresentativeStudent',
            'User',
            'Enrollment',
            'beginTransaction',
            'commit(',
            'rollBack',
        ] as $forbidden) {
            assertComposite(!str_contains($source, $forbidden), 'Forbidden orchestration token: ' . $forbidden);
        }
        assertComposite(substr_count($source, '->run(') === 2, 'Each orchestration must own one runner call.');
    });
}

/** @param array<string, mixed> $changes */
function compositeRepresentativeInput(array $changes = []): CreateRepresentativeFamilyInput
{
    $values = array_replace([
        'firstName' => '  Composite  ',
        'middleName' => 'Representative',
        'firstSurname' => 'Flow',
        'secondSurname' => null,
        'documentTypeId' => 1,
        'documentNumber' => 'COMPOSITE-REP-001',
        'birthDate' => compositeDate('1980-01-02'),
        'sexId' => 1,
        'maritalStatusId' => 2,
        'educationLevelId' => 3,
        'email' => 'representative@example.test',
        'mobilePhone' => 'free form mobile',
        'landlinePhone' => null,
        'personStatus' => PersonStatus::Inactive,
        'occupation' => 'Engineer',
        'companyName' => 'Independent',
        'position' => null,
        'workPhone' => 'work extension',
        'workEmail' => 'work@example.test',
        'representativeStatus' => RepresentativeStatus::Active,
        'displayName' => '  Composite Representative Family  ',
        'familyStatus' => FamilyStatus::Inactive,
        'relationshipTypeId' => 11,
        'startedAt' => compositeDate('2026-08-05 10:11:12.987654-05:00'),
    ], $changes);

    return new CreateRepresentativeFamilyInput(...$values);
}

/** @param array<string, mixed> $changes */
function compositeStudentInput(int $familyId, array $changes = []): CreateStudentInFamilyInput
{
    $values = array_replace([
        'familyId' => $familyId,
        'firstName' => 'Composite',
        'middleName' => 'Student',
        'firstSurname' => 'Flow',
        'secondSurname' => null,
        'documentTypeId' => 1,
        'documentNumber' => 'COMPOSITE-STUDENT-PERSON-001',
        'birthDate' => compositeDate('2015-03-04'),
        'sexId' => 1,
        'maritalStatusId' => null,
        'educationLevelId' => null,
        'email' => null,
        'mobilePhone' => null,
        'landlinePhone' => null,
        'personStatus' => PersonStatus::Active,
        'institutionalCode' => 'COMPOSITE-STUDENT-001',
        'admissionDate' => compositeDate('2026-08-05 15:30:00-05:00'),
        'studentStatus' => StudentStatus::Inactive,
        'startedAt' => compositeDate('2026-08-05 18:19:20.765432+02:00'),
    ], $changes);

    return new CreateStudentInFamilyInput(...$values);
}

function compositePersonFixture(int $id, string $documentNumber): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Existing', null, 'Person', null),
        new Identification(1, $documentNumber),
        compositeDate('1980-01-01'),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        compositeDate('2026-08-05'),
    );
}

function compositeStudentFixture(int $id, int $personId, string $institutionalCode): Student
{
    return new Student(
        new StudentId($id),
        new StudentPersonId($personId),
        new InstitutionalCode($institutionalCode),
        new AdmissionDate(compositeDate('2025-01-01'), compositeDate('2026-08-05')),
        StudentStatus::Active,
    );
}

function compositeDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

/** @return array{string, string, string, string} */
function compositeRepositoryState(CompositeOrchestrationEnvironment $environment): array
{
    return [
        serialize($environment->persons),
        serialize($environment->representatives),
        serialize($environment->students),
        serialize($environment->families),
    ];
}

/** @param array{string, string, string, string} $before */
function assertCompositeRollback(
    CompositeOrchestrationEnvironment $environment,
    array $before,
    string $label,
): void {
    assertComposite(
        compositeRepositoryState($environment) === $before,
        $label . ' did not restore every repository exactly.'
    );
    assertComposite($environment->transactions->beginCount() === 1, $label . ' begin count differs.');
    assertComposite($environment->transactions->commitCount() === 0, $label . ' committed.');
    assertComposite($environment->transactions->rollbackCount() === 1, $label . ' did not roll back.');
    assertComposite(!$environment->transactions->isActive(), $label . ' left a transaction active.');
}

function assertCompositeTransactionCommitted(InMemoryCompositeTransactionRunner $transactions): void
{
    assertComposite($transactions->beginCount() === 1, 'Composite flow did not begin once.');
    assertComposite($transactions->commitCount() === 1, 'Composite flow did not commit once.');
    assertComposite($transactions->rollbackCount() === 0, 'Successful composite flow rolled back.');
    assertComposite(!$transactions->isActive(), 'Successful composite flow left a transaction active.');
}

function compositeCaught(callable $operation): ?Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

function assertComposite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function compositeOrchestrationSource(): string
{
    $directory = dirname(__DIR__) . '/app/Family/Application/Orchestration';
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        $files,
    ));
}
