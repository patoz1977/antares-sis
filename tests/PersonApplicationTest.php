<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\GetPerson;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerPersonApplicationTests(TestRunner $runner): void
{
    $runner->add('CreatePerson creates a persisted Person without identification or defaults', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $output = (new CreatePerson($repository))->handle(
            minimalCreatePersonInput(PersonStatus::Inactive),
            applicationToday(),
        );

        assertSameValue(true, $output->id > 0);
        assertSameValue(null, $output->documentTypeId);
        assertSameValue(null, $output->documentNumber);
        assertSameValue(null, $output->email);
        assertSameValue(PersonStatus::Inactive, $output->status);
        assertSameValue(1, $repository->saveCalls());
    });

    $runner->add('CreatePerson returns every approved field and repository-generated identity', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday(), 40);
        $output = (new CreatePerson($repository))->handle(fullCreatePersonInput(), applicationToday());

        assertSameValue(40, $output->id);
        assertSameValue('Ana', $output->firstName);
        assertSameValue('Maria', $output->middleName);
        assertSameValue('Perez', $output->firstSurname);
        assertSameValue('Lopez', $output->secondSurname);
        assertSameValue(2, $output->documentTypeId);
        assertSameValue('Doc-200', $output->documentNumber);
        assertSameValue('2000-02-03 00:00:00', $output->birthDate->format('Y-m-d H:i:s'));
        assertSameValue(3, $output->sexId);
        assertSameValue(4, $output->maritalStatusId);
        assertSameValue(5, $output->educationLevelId);
        assertSameValue('ana@example.test', $output->email);
        assertSameValue('mobile extension', $output->mobilePhone);
        assertSameValue('landline extension', $output->landlinePhone);
        assertSameValue(PersonStatus::Active, $output->status);
    });

    $runner->add('CreatePerson rejects an identification already owned by another Person', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(10, 'DOC-200'));
        $input = new CreatePersonInput(
            'Duplicate',
            null,
            'Person',
            null,
            2,
            '  doc-200  ',
            new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC')),
            3,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        );

        assertThrows(
            static fn () => (new CreatePerson($repository))->handle($input, applicationToday()),
            IdentificationAlreadyUsed::class,
        );
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('CreatePerson propagates domain invariants without saving', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $input = new CreatePersonInput(
            ' ',
            null,
            'Person',
            null,
            null,
            null,
            new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        );

        assertThrows(
            static fn () => (new CreatePerson($repository))->handle($input, applicationToday()),
            InvalidPersonState::class,
        );
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('CreatePerson rejects a future birth date using explicit today', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $input = new CreatePersonInput(
            'Future',
            null,
            'Person',
            null,
            null,
            null,
            new DateTimeImmutable('2026-08-02 00:00:00', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        );

        assertThrows(
            static fn () => (new CreatePerson($repository))->handle($input, applicationToday()),
            InvalidPersonState::class,
        );
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('CreatePerson rejects a repository result without generated identity', function (): void {
        $repository = new class implements PersonRepository {
            public function findById(PersonId $id): ?Person
            {
                return null;
            }

            public function findByIdentification(Identification $identification): ?Person
            {
                return null;
            }

            public function save(Person $person): Person
            {
                return $person;
            }
        };

        assertThrows(
            static fn () => (new CreatePerson($repository))->handle(
                minimalCreatePersonInput(PersonStatus::Active),
                applicationToday(),
            ),
            InvalidPersistedPersonResult::class,
        );
    });

    $runner->add('GetPerson returns the complete immutable output', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(15, 'GET-15'));

        $output = (new GetPerson($repository))->handle(15);

        assertSameValue(15, $output->id);
        assertSameValue('Stored', $output->firstName);
        assertSameValue('Complete', $output->middleName);
        assertSameValue('Person', $output->firstSurname);
        assertSameValue('Record', $output->secondSurname);
        assertSameValue(2, $output->documentTypeId);
        assertSameValue('GET-15', $output->documentNumber);
        assertSameValue('1990-05-06', $output->birthDate->format('Y-m-d'));
        assertSameValue(3, $output->sexId);
        assertSameValue(4, $output->maritalStatusId);
        assertSameValue(5, $output->educationLevelId);
        assertSameValue('stored@example.test', $output->email);
        assertSameValue('stored mobile', $output->mobilePhone);
        assertSameValue('stored landline', $output->landlinePhone);
        assertSameValue(PersonStatus::Active, $output->status);
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
    });

    $runner->add('GetPerson throws the specific application error when absent', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());

        assertThrows(
            static fn () => (new GetPerson($repository))->handle(999),
            PersonNotFound::class,
        );
    });

    $runner->add('UpdatePerson replaces all editable data and preserves identity', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(21, 'OLD-21'));

        $output = (new UpdatePerson($repository))->handle(
            fullUpdatePersonInput(21, PersonStatus::Inactive),
            applicationToday(),
        );

        assertSameValue(21, $output->id);
        assertSameValue('Updated', $output->firstName);
        assertSameValue(null, $output->middleName);
        assertSameValue('Identity', $output->firstSurname);
        assertSameValue(null, $output->secondSurname);
        assertSameValue(6, $output->documentTypeId);
        assertSameValue('NEW-600', $output->documentNumber);
        assertSameValue('2001-07-08', $output->birthDate->format('Y-m-d'));
        assertSameValue(7, $output->sexId);
        assertSameValue(8, $output->maritalStatusId);
        assertSameValue(9, $output->educationLevelId);
        assertSameValue('updated@example.test', $output->email);
        assertSameValue('updated mobile', $output->mobilePhone);
        assertSameValue('updated landline', $output->landlinePhone);
        assertSameValue(PersonStatus::Inactive, $output->status);
        assertSameValue(1, $repository->saveCalls());
    });

    $runner->add('UpdatePerson removes optional identification and contact', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(22, 'REMOVE-22'));
        $input = new UpdatePersonInput(
            22,
            'Stored',
            'Complete',
            'Person',
            'Record',
            null,
            null,
            new DateTimeImmutable('1990-05-06', new DateTimeZone('UTC')),
            3,
            4,
            5,
            null,
            null,
            null,
            PersonStatus::Active,
        );

        $output = (new UpdatePerson($repository))->handle($input, applicationToday());

        assertSameValue(null, $output->documentTypeId);
        assertSameValue(null, $output->documentNumber);
        assertSameValue(null, $output->email);
        assertSameValue(null, $output->mobilePhone);
        assertSameValue(null, $output->landlinePhone);
        assertSameValue(null, $repository->findById(new PersonId(22))?->identification());
        assertSameValue(null, $repository->findById(new PersonId(22))?->contactInformation());
    });

    $runner->add('UpdatePerson applies both GENERAL_STATUS lifecycle directions', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(23, 'STATUS-23'));
        $useCase = new UpdatePerson($repository);

        $inactive = $useCase->handle(
            fullUpdatePersonInput(23, PersonStatus::Inactive, 'STATUS-23'),
            applicationToday(),
        );
        $active = $useCase->handle(
            fullUpdatePersonInput(23, PersonStatus::Active, 'STATUS-23'),
            applicationToday(),
        );

        assertSameValue(PersonStatus::Inactive, $inactive->status);
        assertSameValue(PersonStatus::Active, $active->status);
    });

    $runner->add('UpdatePerson throws the specific application error when absent', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());

        assertThrows(
            static fn () => (new UpdatePerson($repository))->handle(
                fullUpdatePersonInput(999, PersonStatus::Active),
                applicationToday(),
            ),
            PersonNotFound::class,
        );
    });

    $runner->add('UpdatePerson rejects identification owned by another Person', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(30, 'OWNER-30', 6));
        $repository->seed(applicationPerson(31, 'TARGET-31'));

        assertThrows(
            static fn () => (new UpdatePerson($repository))->handle(
                fullUpdatePersonInput(31, PersonStatus::Active, ' owner-30 '),
                applicationToday(),
            ),
            IdentificationAlreadyUsed::class,
        );
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('UpdatePerson allows the current Person to retain its identification', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(32, 'SAME-32', 6));

        $output = (new UpdatePerson($repository))->handle(
            fullUpdatePersonInput(32, PersonStatus::Active, ' same-32 '),
            applicationToday(),
        );

        assertSameValue(32, $output->id);
        assertSameValue('same-32', $output->documentNumber);
        assertSameValue(1, $repository->saveCalls());
    });

    $runner->add('UpdatePerson preserves persisted identity returned by the repository', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(33, 'IMMUTABLE-33'));

        $output = (new UpdatePerson($repository))->handle(
            fullUpdatePersonInput(33, PersonStatus::Inactive, 'IMMUTABLE-33'),
            applicationToday(),
        );

        assertSameValue(33, $output->id);
        assertSameValue(33, $repository->lastSaved()?->id()?->value());
    });

    $runner->add('UpdatePerson domain failure is atomic and does not save partial state', function (): void {
        $repository = new InMemoryPersonApplicationRepository(applicationToday());
        $repository->seed(applicationPerson(34, 'ATOMIC-34'));
        $input = new UpdatePersonInput(
            34,
            'Changed',
            null,
            'BeforeFailure',
            null,
            6,
            'CHANGED-34',
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
            7,
            null,
            null,
            'changed@example.test',
            null,
            null,
            PersonStatus::Inactive,
        );

        assertThrows(
            static fn () => (new UpdatePerson($repository))->handle($input, applicationToday()),
            InvalidPersonState::class,
        );

        $stored = $repository->findById(new PersonId(34));
        assertSameValue(0, $repository->saveCalls());
        assertSameValue('Stored', $stored?->personalName()->firstName());
        assertSameValue('ATOMIC-34', $stored?->identification()?->documentNumber());
        assertSameValue(PersonStatus::Active, $stored?->status());
    });

    $runner->add('Person Application depends only on Domain contracts and approved DTOs', function (): void {
        foreach ([CreatePerson::class, GetPerson::class, UpdatePerson::class] as $useCase) {
            $parameter = (new ReflectionClass($useCase))->getConstructor()?->getParameters()[0] ?? null;
            assertSameValue(PersonRepository::class, $parameter?->getType()?->getName());
        }

        $root = dirname(__DIR__) . '/app/Person/Application';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        assertSameValue(9, count($files));

        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
        foreach (['PDO', '\\Infrastructure\\', '\\Http\\', '\\Controllers\\', '\\Views\\'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        assertSameValue(0, preg_match_all('/\binterface\s+[A-Za-z_]/', $source));
    });
}

function applicationToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01 23:59:59', new DateTimeZone('UTC'));
}

function minimalCreatePersonInput(PersonStatus $status): CreatePersonInput
{
    return new CreatePersonInput(
        'Minimal',
        null,
        'Person',
        null,
        null,
        null,
        new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        null,
        null,
        $status,
    );
}

function fullCreatePersonInput(): CreatePersonInput
{
    return new CreatePersonInput(
        '  Ana  ',
        ' Maria ',
        ' Perez ',
        ' Lopez ',
        2,
        ' Doc-200 ',
        new DateTimeImmutable('2000-02-03 14:15:16', new DateTimeZone('UTC')),
        3,
        4,
        5,
        ' ana@example.test ',
        ' mobile extension ',
        ' landline extension ',
        PersonStatus::Active,
    );
}

function fullUpdatePersonInput(
    int $personId,
    PersonStatus $status,
    string $documentNumber = 'NEW-600',
): UpdatePersonInput {
    return new UpdatePersonInput(
        $personId,
        'Updated',
        null,
        'Identity',
        null,
        6,
        $documentNumber,
        new DateTimeImmutable('2001-07-08', new DateTimeZone('UTC')),
        7,
        8,
        9,
        'updated@example.test',
        'updated mobile',
        'updated landline',
        $status,
    );
}

function applicationPerson(int $id, string $documentNumber, int $documentTypeId = 2): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Stored', 'Complete', 'Person', 'Record'),
        new Identification($documentTypeId, $documentNumber),
        new DateTimeImmutable('1990-05-06', new DateTimeZone('UTC')),
        3,
        4,
        5,
        new ContactInformation('stored@example.test', 'stored mobile', 'stored landline'),
        PersonStatus::Active,
        applicationToday(),
    );
}
