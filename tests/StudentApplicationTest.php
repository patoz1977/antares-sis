<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;
use App\Student\Application\CreateStudent;
use App\Student\Application\Dto\CreateStudentInput;
use App\Student\Application\Dto\UpdateStudentInput;
use App\Student\Application\Exception\InstitutionalCodeAlreadyUsed;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Application\Exception\StudentAlreadyExistsForPerson;
use App\Student\Application\Exception\StudentNotFound;
use App\Student\Application\Exception\StudentPersonNotFound;
use App\Student\Application\GetStudent;
use App\Student\Application\UpdateStudent;
use App\Student\Domain\Exception\InvalidStudentState;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerStudentApplicationTests(TestRunner $runner): void
{
    $runner->add('CreateStudent persists a role for an existing Person and returns complete output', function (): void {
        $persons = studentPersonRepository(30);
        $students = new InMemoryStudentApplicationRepository(901);
        $input = new CreateStudentInput(
            30,
            ' STU-030 ',
            new DateTimeImmutable('2026-08-01 18:45:00', new DateTimeZone('UTC')),
            StudentStatus::Inactive,
        );

        $output = (new CreateStudent($persons, $students))->handle($input, studentToday());

        assertSameValue(901, $output->id);
        assertSameValue(30, $output->personId);
        assertSameValue('STU-030', $output->institutionalCode);
        assertSameValue('2026-08-01 00:00:00', $output->admissionDate->format('Y-m-d H:i:s'));
        assertSameValue(StudentStatus::Inactive, $output->status);
        assertSameValue(1, $students->saveCalls());
    });

    $runner->add('CreateStudent accepts today deterministically regardless of time component', function (): void {
        $persons = studentPersonRepository(31);
        $students = new InMemoryStudentApplicationRepository();
        $today = new DateTimeImmutable('2026-08-01 00:01:02', new DateTimeZone('UTC'));
        $input = new CreateStudentInput(
            31,
            'TODAY-31',
            new DateTimeImmutable('2026-08-01 23:59:59', new DateTimeZone('UTC')),
            StudentStatus::Active,
        );

        $output = (new CreateStudent($persons, $students))->handle($input, $today);

        assertSameValue('2026-08-01 00:00:00', $output->admissionDate->format('Y-m-d H:i:s'));
        assertSameValue(StudentStatus::Active, $output->status);
    });

    $runner->add('CreateStudent rejects a missing Person without saving', function (): void {
        $persons = new InMemoryPersonApplicationRepository(studentToday());
        $students = new InMemoryStudentApplicationRepository();

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle(
                studentCreateInput(99, 'MISSING-99'),
                studentToday(),
            ),
            StudentPersonNotFound::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('CreateStudent rejects a second role for the same Person', function (): void {
        $persons = studentPersonRepository(32);
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(61, 32, 'FIRST-32'));

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle(
                studentCreateInput(32, 'SECOND-32'),
                studentToday(),
            ),
            StudentAlreadyExistsForPerson::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('CreateStudent rejects an InstitutionalCode owned by another Student', function (): void {
        $persons = studentPersonRepository(33);
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(62, 34, 'USED-CODE'));

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle(
                studentCreateInput(33, 'USED-CODE'),
                studentToday(),
            ),
            InstitutionalCodeAlreadyUsed::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('CreateStudent propagates future AdmissionDate invariant without saving', function (): void {
        $persons = studentPersonRepository(35);
        $students = new InMemoryStudentApplicationRepository();
        $input = new CreateStudentInput(
            35,
            'FUTURE-35',
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
            StudentStatus::Active,
        );

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle($input, studentToday()),
            InvalidStudentState::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('CreateStudent propagates invalid InstitutionalCode without saving', function (): void {
        $persons = studentPersonRepository(36);
        $students = new InMemoryStudentApplicationRepository();

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle(
                studentCreateInput(36, ' '),
                studentToday(),
            ),
            InvalidStudentState::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('CreateStudent rejects a repository result without generated identity', function (): void {
        $persons = studentPersonRepository(37);
        $students = new InMemoryStudentApplicationRepository();
        $students->returnWithoutId();

        assertThrows(
            static fn () => (new CreateStudent($persons, $students))->handle(
                studentCreateInput(37, 'NO-ID-37'),
                studentToday(),
            ),
            InvalidPersistedStudentResult::class,
        );
    });

    $runner->add('GetStudent returns a complete immutable output', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(70, 38, 'GET-38', '2020-02-03', StudentStatus::Inactive));

        $output = (new GetStudent($students))->handle(70);

        assertSameValue(70, $output->id);
        assertSameValue(38, $output->personId);
        assertSameValue('GET-38', $output->institutionalCode);
        assertSameValue('2020-02-03', $output->admissionDate->format('Y-m-d'));
        assertSameValue(StudentStatus::Inactive, $output->status);
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
    });

    $runner->add('GetStudent throws the specific application error when absent', function (): void {
        assertThrows(
            static fn () => (new GetStudent(new InMemoryStudentApplicationRepository()))->handle(999),
            StudentNotFound::class,
        );
    });

    $runner->add('UpdateStudent updates InstitutionalCode and preserves identities', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(71, 39, 'OLD-39', '2020-01-01'));

        $output = (new UpdateStudent($students))->handle(
            studentUpdateInput(71, 'NEW-39', '2020-01-01', StudentStatus::Active),
            studentToday(),
        );

        assertSameValue(71, $output->id);
        assertSameValue(39, $output->personId);
        assertSameValue('NEW-39', $output->institutionalCode);
        assertSameValue('2020-01-01', $output->admissionDate->format('Y-m-d'));
    });

    $runner->add('UpdateStudent updates AdmissionDate independently', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(72, 40, 'SAME-40', '2020-01-01'));

        $output = (new UpdateStudent($students))->handle(
            studentUpdateInput(72, 'SAME-40', '2021-02-03', StudentStatus::Active),
            studentToday(),
        );

        assertSameValue('SAME-40', $output->institutionalCode);
        assertSameValue('2021-02-03', $output->admissionDate->format('Y-m-d'));
    });

    $runner->add('UpdateStudent updates academic information together and changes status', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(73, 41, 'OLD-41', '2020-01-01'));
        $useCase = new UpdateStudent($students);

        $inactive = $useCase->handle(
            studentUpdateInput(73, 'NEW-41', '2022-03-04', StudentStatus::Inactive),
            studentToday(),
        );
        $active = $useCase->handle(
            studentUpdateInput(73, 'NEW-41', '2022-03-04', StudentStatus::Active),
            studentToday(),
        );

        assertSameValue('NEW-41', $inactive->institutionalCode);
        assertSameValue('2022-03-04', $inactive->admissionDate->format('Y-m-d'));
        assertSameValue(StudentStatus::Inactive, $inactive->status);
        assertSameValue(StudentStatus::Active, $active->status);
    });

    $runner->add('UpdateStudent throws the specific application error when absent', function (): void {
        assertThrows(
            static fn () => (new UpdateStudent(new InMemoryStudentApplicationRepository()))->handle(
                studentUpdateInput(999, 'ABSENT', '2020-01-01', StudentStatus::Active),
                studentToday(),
            ),
            StudentNotFound::class,
        );
    });

    $runner->add('UpdateStudent rejects InstitutionalCode owned by another Student', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(74, 42, 'OWNER-42'));
        $students->seed(studentAggregate(75, 43, 'TARGET-43'));

        assertThrows(
            static fn () => (new UpdateStudent($students))->handle(
                studentUpdateInput(75, 'OWNER-42', '2020-01-01', StudentStatus::Active),
                studentToday(),
            ),
            InstitutionalCodeAlreadyUsed::class,
        );
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('UpdateStudent permits the current Student to retain its InstitutionalCode', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(76, 44, 'SAME-44'));

        $output = (new UpdateStudent($students))->handle(
            studentUpdateInput(76, 'SAME-44', '2021-01-01', StudentStatus::Active),
            studentToday(),
        );

        assertSameValue(76, $output->id);
        assertSameValue('SAME-44', $output->institutionalCode);
        assertSameValue(1, $students->saveCalls());
    });

    $runner->add('UpdateStudent future date failure does not save or mutate persisted state', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(77, 45, 'ATOMIC-45', '2020-01-01'));

        assertThrows(
            static fn () => (new UpdateStudent($students))->handle(
                studentUpdateInput(77, 'CHANGED-45', '2026-08-02', StudentStatus::Inactive),
                studentToday(),
            ),
            InvalidStudentState::class,
        );

        $stored = $students->findById(new StudentId(77));
        assertSameValue(0, $students->saveCalls());
        assertSameValue('ATOMIC-45', $stored?->institutionalCode()->value());
        assertSameValue('2020-01-01', $stored?->admissionDate()->value()->format('Y-m-d'));
        assertSameValue(StudentStatus::Active, $stored?->status());
    });

    $runner->add('UpdateStudent invalid code does not mutate persisted state', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(78, 46, 'VALID-46'));

        assertThrows(
            static fn () => (new UpdateStudent($students))->handle(
                studentUpdateInput(78, '', '2020-01-01', StudentStatus::Inactive),
                studentToday(),
            ),
            InvalidStudentState::class,
        );
        assertSameValue('VALID-46', $students->findById(new StudentId(78))?->institutionalCode()->value());
        assertSameValue(0, $students->saveCalls());
    });

    $runner->add('UpdateStudent rejects a persisted result without identity', function (): void {
        $students = new InMemoryStudentApplicationRepository();
        $students->seed(studentAggregate(79, 47, 'NO-ID-47'));
        $students->returnWithoutId();

        assertThrows(
            static fn () => (new UpdateStudent($students))->handle(
                studentUpdateInput(79, 'NO-ID-47', '2020-01-01', StudentStatus::Active),
                studentToday(),
            ),
            InvalidPersistedStudentResult::class,
        );
    });

    $runner->add('Student Application depends only on approved Domain contracts', function (): void {
        $createParameters = (new ReflectionClass(CreateStudent::class))->getConstructor()?->getParameters();
        assertSameValue(PersonRepository::class, $createParameters[0]->getType()?->getName());
        assertSameValue(StudentRepository::class, $createParameters[1]->getType()?->getName());
        foreach ([GetStudent::class, UpdateStudent::class] as $useCase) {
            $parameter = (new ReflectionClass($useCase))->getConstructor()?->getParameters()[0] ?? null;
            assertSameValue(StudentRepository::class, $parameter?->getType()?->getName());
        }

        $source = studentApplicationSource();
        foreach (['PDO', '\\Infrastructure\\', '\\Http\\', '\\Controllers\\', '\\Views\\'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        foreach (['Grade', 'Section', 'Family', 'Enrollment'] as $excludedConcept) {
            assertSameValue(false, str_contains($source, $excludedConcept));
        }
    });
}

function studentToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01 23:59:59', new DateTimeZone('UTC'));
}

function studentPersonRepository(int $personId): InMemoryPersonApplicationRepository
{
    $repository = new InMemoryPersonApplicationRepository(studentToday());
    $repository->seed(new Person(
        new PersonDomainId($personId),
        new PersonalName('Existing', null, 'Student Person', null),
        null,
        new DateTimeImmutable('2010-01-01', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        studentToday(),
    ));

    return $repository;
}

function studentCreateInput(int $personId, string $institutionalCode): CreateStudentInput
{
    return new CreateStudentInput(
        $personId,
        $institutionalCode,
        new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')),
        StudentStatus::Active,
    );
}

function studentUpdateInput(
    int $studentId,
    string $institutionalCode,
    string $admissionDate,
    StudentStatus $status,
): UpdateStudentInput {
    return new UpdateStudentInput(
        $studentId,
        $institutionalCode,
        new DateTimeImmutable($admissionDate, new DateTimeZone('UTC')),
        $status,
    );
}

function studentAggregate(
    int $id,
    int $personId,
    string $institutionalCode,
    string $admissionDate = '2020-01-01',
    StudentStatus $status = StudentStatus::Active,
): Student {
    return new Student(
        new StudentId($id),
        new PersonId($personId),
        new InstitutionalCode($institutionalCode),
        new AdmissionDate(
            new DateTimeImmutable($admissionDate, new DateTimeZone('UTC')),
            studentToday(),
        ),
        $status,
    );
}

function studentApplicationSource(): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/app/Student/Application'
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        $files,
    ));
}
