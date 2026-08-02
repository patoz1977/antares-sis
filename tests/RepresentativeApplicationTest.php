<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Application\Dto\UpdateRepresentativeInput;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Application\Exception\RepresentativeAlreadyExistsForPerson;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Application\Exception\RepresentativePersonNotFound;
use App\Representative\Application\GetRepresentative;
use App\Representative\Application\UpdateRepresentative;
use App\Representative\Domain\Exception\InvalidRepresentativeState;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerRepresentativeApplicationTests(TestRunner $runner): void
{
    $runner->add('CreateRepresentative persists a role without employment for an existing Person', function (): void {
        $persons = representativePersonRepository(10);
        $representatives = new InMemoryRepresentativeApplicationRepository(701);

        $output = (new CreateRepresentative($persons, $representatives))->handle(
            representativeCreateInput(10, RepresentativeStatus::Inactive),
        );

        assertSameValue(701, $output->id);
        assertSameValue(10, $output->personId);
        assertSameValue(null, $output->occupation);
        assertSameValue(null, $output->companyName);
        assertSameValue(null, $output->position);
        assertSameValue(null, $output->workPhone);
        assertSameValue(null, $output->workEmail);
        assertSameValue(RepresentativeStatus::Inactive, $output->status);
        assertSameValue(null, $representatives->findById(new RepresentativeId(701))?->employmentInformation());
        assertSameValue(1, $representatives->saveCalls());
    });

    $runner->add('CreateRepresentative returns complete normalized employment information', function (): void {
        $persons = representativePersonRepository(11);
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $input = new CreateRepresentativeInput(
            11,
            ' Engineer ',
            ' Example Company ',
            ' Lead ',
            ' extension alpha ',
            ' work@example.test ',
            RepresentativeStatus::Active,
        );

        $output = (new CreateRepresentative($persons, $representatives))->handle($input);

        assertSameValue(true, $output->id > 0);
        assertSameValue('Engineer', $output->occupation);
        assertSameValue('Example Company', $output->companyName);
        assertSameValue('Lead', $output->position);
        assertSameValue('extension alpha', $output->workPhone);
        assertSameValue('work@example.test', $output->workEmail);
        assertSameValue(RepresentativeStatus::Active, $output->status);
    });

    $runner->add('CreateRepresentative converts wholly empty employment input into absence', function (): void {
        $persons = representativePersonRepository(12);
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $input = new CreateRepresentativeInput(
            12,
            '',
            ' ',
            null,
            "\t",
            null,
            RepresentativeStatus::Active,
        );

        $output = (new CreateRepresentative($persons, $representatives))->handle($input);

        assertSameValue(null, $output->occupation);
        assertSameValue(null, $representatives->findByPersonId(new PersonId(12))?->employmentInformation());
    });

    $runner->add('CreateRepresentative rejects a missing Person without saving', function (): void {
        $persons = new InMemoryPersonApplicationRepository(representativeToday());
        $representatives = new InMemoryRepresentativeApplicationRepository();

        assertThrows(
            static fn () => (new CreateRepresentative($persons, $representatives))->handle(
                representativeCreateInput(99, RepresentativeStatus::Active),
            ),
            RepresentativePersonNotFound::class,
        );
        assertSameValue(0, $representatives->saveCalls());
    });

    $runner->add('CreateRepresentative rejects a second role for the same Person', function (): void {
        $persons = representativePersonRepository(13);
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(31, 13));

        assertThrows(
            static fn () => (new CreateRepresentative($persons, $representatives))->handle(
                representativeCreateInput(13, RepresentativeStatus::Active),
            ),
            RepresentativeAlreadyExistsForPerson::class,
        );
        assertSameValue(0, $representatives->saveCalls());
    });

    $runner->add('CreateRepresentative propagates employment invariants without saving', function (): void {
        $persons = representativePersonRepository(14);
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $input = new CreateRepresentativeInput(
            14,
            null,
            null,
            null,
            null,
            'invalid-email',
            RepresentativeStatus::Active,
        );

        assertThrows(
            static fn () => (new CreateRepresentative($persons, $representatives))->handle($input),
            InvalidRepresentativeState::class,
        );
        assertSameValue(0, $representatives->saveCalls());
    });

    $runner->add('CreateRepresentative rejects a repository result without generated identity', function (): void {
        $persons = representativePersonRepository(15);
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->returnWithoutId();

        assertThrows(
            static fn () => (new CreateRepresentative($persons, $representatives))->handle(
                representativeCreateInput(15, RepresentativeStatus::Active),
            ),
            InvalidPersistedRepresentativeResult::class,
        );
    });

    $runner->add('GetRepresentative returns a complete immutable output', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(40, 16, RepresentativeStatus::Inactive, true));

        $output = (new GetRepresentative($representatives))->handle(40);

        assertSameValue(40, $output->id);
        assertSameValue(16, $output->personId);
        assertSameValue('Teacher', $output->occupation);
        assertSameValue('School', $output->companyName);
        assertSameValue('Coordinator', $output->position);
        assertSameValue('phone words', $output->workPhone);
        assertSameValue('role@example.test', $output->workEmail);
        assertSameValue(RepresentativeStatus::Inactive, $output->status);
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
    });

    $runner->add('GetRepresentative throws the specific application error when absent', function (): void {
        assertThrows(
            static fn () => (new GetRepresentative(
                new InMemoryRepresentativeApplicationRepository()
            ))->handle(999),
            RepresentativeNotFound::class,
        );
    });

    $runner->add('UpdateRepresentative replaces complete employment and preserves identities', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(50, 17));
        $input = new UpdateRepresentativeInput(
            50,
            'Updated occupation',
            'Updated company',
            'Updated position',
            'updated phone format',
            'updated@example.test',
            RepresentativeStatus::Inactive,
        );

        $output = (new UpdateRepresentative($representatives))->handle($input);

        assertSameValue(50, $output->id);
        assertSameValue(17, $output->personId);
        assertSameValue('Updated occupation', $output->occupation);
        assertSameValue('Updated company', $output->companyName);
        assertSameValue('Updated position', $output->position);
        assertSameValue('updated phone format', $output->workPhone);
        assertSameValue('updated@example.test', $output->workEmail);
        assertSameValue(RepresentativeStatus::Inactive, $output->status);
        assertSameValue(1, $representatives->saveCalls());
    });

    $runner->add('UpdateRepresentative removes employment information', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(51, 18, RepresentativeStatus::Active, true));

        $output = (new UpdateRepresentative($representatives))->handle(
            new UpdateRepresentativeInput(51, null, ' ', '', null, "\t", RepresentativeStatus::Active),
        );

        assertSameValue(null, $output->occupation);
        assertSameValue(null, $output->workEmail);
        assertSameValue(null, $representatives->findById(new RepresentativeId(51))?->employmentInformation());
    });

    $runner->add('UpdateRepresentative applies ACTIVE and INACTIVE through aggregate behavior', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(52, 19));
        $useCase = new UpdateRepresentative($representatives);

        $inactive = $useCase->handle(
            new UpdateRepresentativeInput(52, null, null, null, null, null, RepresentativeStatus::Inactive),
        );
        $active = $useCase->handle(
            new UpdateRepresentativeInput(52, null, null, null, null, null, RepresentativeStatus::Active),
        );

        assertSameValue(RepresentativeStatus::Inactive, $inactive->status);
        assertSameValue(RepresentativeStatus::Active, $active->status);
    });

    $runner->add('UpdateRepresentative throws the specific error when absent', function (): void {
        assertThrows(
            static fn () => (new UpdateRepresentative(
                new InMemoryRepresentativeApplicationRepository()
            ))->handle(new UpdateRepresentativeInput(
                999,
                null,
                null,
                null,
                null,
                null,
                RepresentativeStatus::Active,
            )),
            RepresentativeNotFound::class,
        );
    });

    $runner->add('UpdateRepresentative invalid Value Object does not save or mutate persisted state', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(53, 20, RepresentativeStatus::Active, true));

        assertThrows(
            static fn () => (new UpdateRepresentative($representatives))->handle(
                new UpdateRepresentativeInput(
                    53,
                    'Changed',
                    null,
                    null,
                    null,
                    'invalid',
                    RepresentativeStatus::Inactive,
                ),
            ),
            InvalidRepresentativeState::class,
        );

        $stored = $representatives->findById(new RepresentativeId(53));
        assertSameValue(0, $representatives->saveCalls());
        assertSameValue('Teacher', $stored?->employmentInformation()?->occupation());
        assertSameValue(RepresentativeStatus::Active, $stored?->status());
    });

    $runner->add('UpdateRepresentative rejects a persisted result without identity', function (): void {
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeAggregate(54, 21));
        $representatives->returnWithoutId();

        assertThrows(
            static fn () => (new UpdateRepresentative($representatives))->handle(
                new UpdateRepresentativeInput(54, null, null, null, null, null, RepresentativeStatus::Active),
            ),
            InvalidPersistedRepresentativeResult::class,
        );
    });

    $runner->add('Representative Application depends only on approved Domain contracts', function (): void {
        $createParameters = (new ReflectionClass(CreateRepresentative::class))->getConstructor()?->getParameters();
        assertSameValue(PersonRepository::class, $createParameters[0]->getType()?->getName());
        assertSameValue(RepresentativeRepository::class, $createParameters[1]->getType()?->getName());
        foreach ([GetRepresentative::class, UpdateRepresentative::class] as $useCase) {
            $parameter = (new ReflectionClass($useCase))->getConstructor()?->getParameters()[0] ?? null;
            assertSameValue(RepresentativeRepository::class, $parameter?->getType()?->getName());
        }

        $source = representativeApplicationSource();
        foreach (['PDO', '\\Infrastructure\\', '\\Http\\', '\\Controllers\\', '\\Views\\'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        foreach (['User', 'Family'] as $excludedConcept) {
            assertSameValue(false, str_contains($source, $excludedConcept));
        }
    });
}

function representativeToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01 23:30:00', new DateTimeZone('UTC'));
}

function representativePersonRepository(int $personId): InMemoryPersonApplicationRepository
{
    $repository = new InMemoryPersonApplicationRepository(representativeToday());
    $repository->seed(new Person(
        new PersonDomainId($personId),
        new PersonalName('Existing', null, 'Person', null),
        null,
        new DateTimeImmutable('1990-01-01', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        representativeToday(),
    ));

    return $repository;
}

function representativeCreateInput(
    int $personId,
    RepresentativeStatus $status,
): CreateRepresentativeInput {
    return new CreateRepresentativeInput(
        $personId,
        null,
        null,
        null,
        null,
        null,
        $status,
    );
}

function representativeAggregate(
    int $id,
    int $personId,
    RepresentativeStatus $status = RepresentativeStatus::Active,
    bool $withEmployment = false,
): Representative {
    return new Representative(
        new RepresentativeId($id),
        new PersonId($personId),
        $withEmployment
            ? new EmploymentInformation(
                'Teacher',
                'School',
                'Coordinator',
                'phone words',
                'role@example.test',
            )
            : null,
        $status,
    );
}

function representativeApplicationSource(): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/app/Representative/Application'
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
