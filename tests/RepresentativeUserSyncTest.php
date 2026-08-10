<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\Orchestration\UpdatePersonWithRepresentativeUserSync;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativeUserSyncTests(TestRunner $runner): void
{
    $runner->add('Person without Representative preserves optional identification and email', function (): void {
        [$useCase, $persons, , , $transactions] = representativeSyncFixture(
            withRepresentative: false,
            withUser: false,
        );

        $output = $useCase->handle(
            representativeSyncInput(documentTypeId: null, documentNumber: null, email: null),
            representativeUserToday(),
        );

        assertSameValue(null, $output->documentTypeId);
        assertSameValue(null, $output->documentNumber);
        assertSameValue(null, $persons->findById(new PersonId(100))?->identification());
        assertSameValue(null, $persons->findById(new PersonId(100))?->contactInformation());
        assertSameValue(1, $transactions->beginCount());
        assertSameValue(1, $transactions->commitCount());
    });

    $runner->add('Person with non-Representative User keeps existing optional Identification behavior', function (): void {
        [$useCase, $persons, , , $transactions] = representativeSyncFixture(
            withRepresentative: false,
            withUser: true,
        );

        $useCase->handle(
            representativeSyncInput(documentTypeId: null, documentNumber: null, email: null),
            representativeUserToday(),
        );

        assertSameValue(null, $persons->findById(new PersonId(100))?->identification());
        assertSameValue(null, $persons->findById(new PersonId(100))?->contactInformation());
        assertSameValue(1, $transactions->commitCount());
    });

    $runner->add('Representative without User cannot lose personal email and may replace it', function (): void {
        [$useCase, $persons, , , $transactions] = representativeSyncFixture(withUser: false);

        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(email: null),
                representativeUserToday(),
            ),
            RepresentativeRequiresContactEmail::class,
        );
        assertSameValue('representative@example.test', $persons->findById(
            new PersonId(100)
        )?->contactInformation()?->email());
        assertSameValue(0, $persons->saveCalls());
        assertSameValue(1, $transactions->rollbackCount());

        $output = $useCase->handle(
            representativeSyncInput(email: 'new-personal@example.test'),
            representativeUserToday(),
        );
        assertSameValue('new-personal@example.test', $output->email);
    });

    $runner->add('Representative with User email change preserves complete authentication state', function (): void {
        [$useCase, $persons, , $users, $transactions] = representativeSyncFixture();
        $before = $users->findByPersonId(new UserPersonId(100));

        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(email: ' '),
                representativeUserToday(),
            ),
            RepresentativeRequiresContactEmail::class,
        );
        assertSameValue(0, $persons->saveCalls());

        $output = $useCase->handle(
            representativeSyncInput(email: 'updated@example.test'),
            representativeUserToday(),
        );
        $stored = $users->findByPersonId(new UserPersonId(100));
        assertSameValue('updated@example.test', $output->email);
        assertSameValue($before?->id()?->value(), $stored?->id()?->value());
        assertSameValue($before?->loginIdentifier()->value(), $stored?->loginIdentifier()->value());
        assertSameValue($before?->passwordHash()->value(), $stored?->passwordHash()->value());
        assertSameValue($before?->status(), $stored?->status());
        assertSameValue($before?->failedLoginAttempts(), $stored?->failedLoginAttempts());
        assertSameValue($before?->lockedAt()?->getTimestamp(), $stored?->lockedAt()?->getTimestamp());
        assertSameValue($before?->lastAccessAt()?->getTimestamp(), $stored?->lastAccessAt()?->getTimestamp());
        assertSameValue(1, $transactions->commitCount());
    });

    $runner->add('Representative User name and document type updates preserve login without User save', function (): void {
        [$useCase, $persons, , $users] = representativeSyncFixture();

        $output = $useCase->handle(
            representativeSyncInput(
                firstName: 'Changed',
                documentTypeId: 11,
                documentNumber: 'Representative-100',
            ),
            representativeUserToday(),
        );

        assertSameValue('Changed', $output->firstName);
        assertSameValue(11, $output->documentTypeId);
        assertSameValue('representative-100', $users->findByPersonId(new UserPersonId(100))?->loginIdentifier()->value());
        assertSameValue(0, $users->saveCalls());
        assertSameValue(1, $persons->saveCalls());
    });

    $runner->add('Representative document change synchronizes login and preserves User state atomically', function (): void {
        [$useCase, $persons, , $users, $transactions] = representativeSyncFixture();
        $before = $users->findByPersonId(new UserPersonId(100));

        $output = $useCase->handle(
            representativeSyncInput(documentNumber: 'New-Document-100'),
            representativeUserToday(),
        );
        $stored = $users->findByPersonId(new UserPersonId(100));

        assertSameValue('New-Document-100', $output->documentNumber);
        assertSameValue('New-Document-100', $persons->findById(new PersonId(100))?->identification()?->documentNumber());
        assertSameValue('new-document-100', $stored?->loginIdentifier()->value());
        assertSameValue($before?->id()?->value(), $stored?->id()?->value());
        assertSameValue($before?->passwordHash()->value(), $stored?->passwordHash()->value());
        assertSameValue($before?->status(), $stored?->status());
        assertSameValue($before?->failedLoginAttempts(), $stored?->failedLoginAttempts());
        assertSameValue($before?->lockedAt()?->getTimestamp(), $stored?->lockedAt()?->getTimestamp());
        assertSameValue($before?->lastAccessAt()?->getTimestamp(), $stored?->lastAccessAt()?->getTimestamp());
        assertSameValue(1, $transactions->beginCount());
        assertSameValue(1, $transactions->commitCount());
        assertSameValue(0, $transactions->rollbackCount());
    });

    $runner->add('Representative User cannot lose or retain incomplete Identification', function (): void {
        [$useCase, $persons, , $users] = representativeSyncFixture();

        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(documentTypeId: null, documentNumber: null),
                representativeUserToday(),
            ),
            RepresentativeUserRequiresIdentification::class,
        );
        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(documentTypeId: null, documentNumber: 'Incomplete'),
                representativeUserToday(),
            ),
            RepresentativeUserRequiresIdentification::class,
        );

        assertSameValue('Representative-100', $persons->findById(new PersonId(100))?->identification()?->documentNumber());
        assertSameValue('representative-100', $users->findByPersonId(new UserPersonId(100))?->loginIdentifier()->value());
    });

    $runner->add('Representative login collision rejects both changes before persistence', function (): void {
        [$useCase, $persons, , $users, $transactions] = representativeSyncFixture();
        $users->seed(representativeUserUser(901, 999, 'occupied-login'));

        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(documentNumber: 'Occupied-Login'),
                representativeUserToday(),
            ),
            RepresentativeLoginIdentifierAlreadyUsed::class,
        );

        assertSameValue('Representative-100', $persons->findById(new PersonId(100))?->identification()?->documentNumber());
        assertSameValue('representative-100', $users->findByPersonId(new UserPersonId(100))?->loginIdentifier()->value());
        assertSameValue(0, $persons->saveCalls());
        assertSameValue(0, $users->saveCalls());
        assertSameValue(1, $transactions->rollbackCount());
    });

    $runner->add('Representative sync rolls back Person when User persistence fails', function (): void {
        [$useCase, $persons, , $users, $transactions] = representativeSyncFixture();
        $failure = new RuntimeException('simulated physical User write failure');
        $users->failNextSave($failure);
        $caught = null;

        try {
            $useCase->handle(
                representativeSyncInput(firstName: 'Partial', documentNumber: 'Rollback-Document'),
                representativeUserToday(),
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        assertSameValue($failure, $caught);
        assertSameValue('Representative', $persons->findById(new PersonId(100))?->personalName()->firstName());
        assertSameValue('Representative-100', $persons->findById(new PersonId(100))?->identification()?->documentNumber());
        assertSameValue('representative-100', $users->findByPersonId(new UserPersonId(100))?->loginIdentifier()->value());
        assertSameValue(1, $transactions->rollbackCount());
        assertSameValue(0, $transactions->commitCount());
    });

    $runner->add('Representative sync rolls back and propagates Person domain failure', function (): void {
        [$useCase, $persons, , $users, $transactions] = representativeSyncFixture();

        assertThrows(
            fn () => $useCase->handle(
                representativeSyncInput(
                    documentNumber: 'Future-Failure',
                    birthDate: representativeUserToday()->modify('+1 day'),
                ),
                representativeUserToday(),
            ),
            InvalidPersonState::class,
        );

        assertSameValue('Representative-100', $persons->findById(new PersonId(100))?->identification()?->documentNumber());
        assertSameValue('representative-100', $users->findByPersonId(new UserPersonId(100))?->loginIdentifier()->value());
        assertSameValue(1, $transactions->rollbackCount());
    });
}

/** @return array{UpdatePersonWithRepresentativeUserSync, InMemoryPersonApplicationRepository, InMemoryRepresentativeApplicationRepository, InMemoryRepresentativeUserRepository, InMemoryCompositeTransactionRunner} */
function representativeSyncFixture(
    bool $withRepresentative = true,
    bool $withUser = true,
): array {
    $persons = new InMemoryPersonApplicationRepository(representativeUserToday());
    $persons->seed(representativeUserPerson(100, new Identification(10, 'Representative-100')));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    if ($withRepresentative) {
        $representatives->seed(representativeUserRepresentative(200, 100));
    }
    $users = new InMemoryRepresentativeUserRepository();
    if ($withUser) {
        $users->seed(new User(
            new UserId(900),
            new UserPersonId(100),
            new LoginIdentifier('representative-100'),
            new PasswordHash('preserved-hash'),
            UserStatus::Disabled,
            3,
            representativeUserToday()->modify('-10 minutes'),
            representativeUserToday()->modify('-1 day'),
        ));
    }
    $transactions = new InMemoryCompositeTransactionRunner([$persons, $users]);
    $useCase = new UpdatePersonWithRepresentativeUserSync(
        new UpdatePerson($persons),
        $persons,
        $users,
        $representatives,
        $transactions,
    );

    return [$useCase, $persons, $representatives, $users, $transactions];
}

function representativeSyncInput(
    string $firstName = 'Representative',
    ?int $documentTypeId = 10,
    ?string $documentNumber = 'Representative-100',
    ?DateTimeImmutable $birthDate = null,
    ?string $email = 'representative@example.test',
): UpdatePersonInput {
    return new UpdatePersonInput(
        100,
        $firstName,
        null,
        'Person',
        null,
        $documentTypeId,
        $documentNumber,
        $birthDate ?? representativeUserToday()->modify('-40 years'),
        20,
        null,
        null,
        $email,
        null,
        null,
        PersonStatus::Active,
    );
}
