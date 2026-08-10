<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Exception\InvalidPersistedUserResult;
use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserAlreadyExists;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserPersonNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativeUserApplicationTests(TestRunner $runner): void
{
    $runner->add('Representative password policy accepts approved minimum and character varieties', function (): void {
        $policy = new RepresentativePasswordPolicy();
        foreach (['abcde', '12345', 'ab12!', '!!!!!'] as $password) {
            $policy->assertValid($password);
        }

        try {
            $policy->assertValid('1234');
            throw new RuntimeException('Expected invalid Representative password.');
        } catch (InvalidRepresentativePassword $exception) {
            assertSameValue(false, str_contains($exception->getMessage(), '1234'));
        }
    });

    $runner->add('CreateRepresentativeUser derives login hashes password and returns safe output', function (): void {
        [$useCase, $persons, , $users] = representativeUserCreateFixture();

        $output = $useCase->handle(new CreateRepresentativeUserInput(
            200,
            'abcde',
            UserStatus::Active,
        ));
        $stored = $users->findByPersonId(new UserPersonId(100));

        assertSameValue(true, $output->userId > 0);
        assertSameValue(100, $output->personId);
        assertSameValue('representative-100', $output->loginIdentifier);
        assertSameValue(UserStatus::Active, $output->status);
        assertSameValue(true, (new NativePasswordHasher())->verify('abcde', $stored?->passwordHash()->value() ?? ''));
        assertSameValue(false, $stored?->passwordHash()->value() === 'abcde');
        assertSameValue(false, property_exists($output, 'password'));
        assertSameValue(false, property_exists($output, 'passwordHash'));
        assertSameValue(0, $persons->saveCalls());
    });

    $runner->add('CreateRepresentativeUser accepts numbers-only password and exact DISABLED status', function (): void {
        [$useCase, , , $users] = representativeUserCreateFixture();

        $output = $useCase->handle(new CreateRepresentativeUserInput(
            200,
            '12345',
            UserStatus::Disabled,
        ));

        assertSameValue(UserStatus::Disabled, $output->status);
        assertSameValue(
            UserStatus::Disabled,
            $users->findByPersonId(new UserPersonId(100))?->status(),
        );
    });

    $runner->add('CreateRepresentativeUser rejects missing Representative and corrupted Person reference', function (): void {
        [$useCase] = representativeUserCreateFixture();
        assertThrows(
            fn () => $useCase->handle(new CreateRepresentativeUserInput(999, 'abcde', UserStatus::Active)),
            RepresentativeNotFound::class,
        );

        $persons = new InMemoryPersonApplicationRepository(representativeUserToday());
        $representatives = new InMemoryRepresentativeApplicationRepository();
        $representatives->seed(representativeUserRepresentative(200, 999));
        $users = new InMemoryRepresentativeUserRepository();
        $corrupted = representativeUserCreateUseCase($representatives, $persons, $users);
        assertThrows(
            fn () => $corrupted->handle(new CreateRepresentativeUserInput(200, 'abcde', UserStatus::Active)),
            RepresentativeUserPersonNotFound::class,
        );
    });

    $runner->add('CreateRepresentativeUser requires identification and rejects four-character password', function (): void {
        [$withoutIdentification, , , $users] = representativeUserCreateFixture(false);
        assertThrows(
            fn () => $withoutIdentification->handle(
                new CreateRepresentativeUserInput(200, 'abcde', UserStatus::Active)
            ),
            RepresentativeUserRequiresIdentification::class,
        );
        assertSameValue(0, $users->saveCalls());

        [$useCase, , , $shortPasswordUsers] = representativeUserCreateFixture();
        assertThrows(
            fn () => $useCase->handle(
                new CreateRepresentativeUserInput(200, '1234', UserStatus::Active)
            ),
            InvalidRepresentativePassword::class,
        );
        assertSameValue(0, $shortPasswordUsers->saveCalls());
    });

    $runner->add('CreateRepresentativeUser rejects existing Person User and occupied global login', function (): void {
        [$existingUseCase, , , $existingUsers] = representativeUserCreateFixture();
        $existingUsers->seed(representativeUserUser(10, 100, 'representative-100'));
        assertThrows(
            fn () => $existingUseCase->handle(
                new CreateRepresentativeUserInput(200, 'abcde', UserStatus::Active)
            ),
            RepresentativeUserAlreadyExists::class,
        );

        [$occupiedUseCase, , , $occupiedUsers] = representativeUserCreateFixture();
        $occupiedUsers->seed(representativeUserUser(11, 999, 'representative-100'));
        assertThrows(
            fn () => $occupiedUseCase->handle(
                new CreateRepresentativeUserInput(200, 'abcde', UserStatus::Active)
            ),
            RepresentativeLoginIdentifierAlreadyUsed::class,
        );
    });

    $runner->add('CreateRepresentativeUser rejects repository result without generated identity', function (): void {
        [$useCase, , , $users] = representativeUserCreateFixture();
        $users->returnWithoutId();

        assertThrows(
            fn () => $useCase->handle(
                new CreateRepresentativeUserInput(200, 'abcde', UserStatus::Active)
            ),
            InvalidPersistedUserResult::class,
        );
    });

    $runner->add('ChangeRepresentativeUserPassword preserves identity login status and auth state', function (): void {
        [, , $representatives, $users] = representativeUserCreateFixture();
        $lockedAt = representativeUserToday()->modify('-20 minutes');
        $lastAccessAt = representativeUserToday()->modify('-1 day');
        $users->seed(new User(
            new UserId(33),
            new UserPersonId(100),
            new LoginIdentifier('representative-100'),
            new PasswordHash((new NativePasswordHasher())->hash('old-password')),
            UserStatus::Disabled,
            5,
            $lockedAt,
            $lastAccessAt,
        ));
        $useCase = new ChangeRepresentativeUserPassword(
            $representatives,
            $users,
            new NativePasswordHasher(),
            new RepresentativePasswordPolicy(),
        );

        $output = $useCase->handle(new ChangeRepresentativeUserPasswordInput(200, 'new-password'));
        $stored = $users->findByPersonId(new UserPersonId(100));

        assertSameValue(33, $output->userId);
        assertSameValue(100, $output->personId);
        assertSameValue('representative-100', $output->loginIdentifier);
        assertSameValue(UserStatus::Disabled, $output->status);
        assertSameValue(5, $stored?->failedLoginAttempts());
        assertSameValue($lockedAt->getTimestamp(), $stored?->lockedAt()?->getTimestamp());
        assertSameValue($lastAccessAt->getTimestamp(), $stored?->lastAccessAt()?->getTimestamp());
        assertSameValue(true, (new NativePasswordHasher())->verify(
            'new-password',
            $stored?->passwordHash()->value() ?? '',
        ));
        assertSameValue(false, property_exists($output, 'passwordHash'));
    });

    $runner->add('ChangeRepresentativeUserPassword rejects missing User and invalid password', function (): void {
        [, , $representatives, $users] = representativeUserCreateFixture();
        $useCase = new ChangeRepresentativeUserPassword(
            $representatives,
            $users,
            new NativePasswordHasher(),
            new RepresentativePasswordPolicy(),
        );
        assertThrows(
            fn () => $useCase->handle(new ChangeRepresentativeUserPasswordInput(200, 'abcde')),
            RepresentativeUserNotFound::class,
        );

        $users->seed(representativeUserUser(44, 100, 'representative-100'));
        assertThrows(
            fn () => $useCase->handle(new ChangeRepresentativeUserPasswordInput(200, '1234')),
            InvalidRepresentativePassword::class,
        );
    });
}

/** @return array{CreateRepresentativeUser, InMemoryPersonApplicationRepository, InMemoryRepresentativeApplicationRepository, InMemoryRepresentativeUserRepository} */
function representativeUserCreateFixture(bool $withIdentification = true): array
{
    $persons = new InMemoryPersonApplicationRepository(representativeUserToday());
    $persons->seed(representativeUserPerson(
        100,
        $withIdentification ? new Identification(10, 'Representative-100') : null,
    ));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $representatives->seed(representativeUserRepresentative(200, 100));
    $users = new InMemoryRepresentativeUserRepository();

    return [
        representativeUserCreateUseCase($representatives, $persons, $users),
        $persons,
        $representatives,
        $users,
    ];
}

function representativeUserCreateUseCase(
    InMemoryRepresentativeApplicationRepository $representatives,
    InMemoryPersonApplicationRepository $persons,
    InMemoryRepresentativeUserRepository $users,
): CreateRepresentativeUser {
    return new CreateRepresentativeUser(
        $representatives,
        $persons,
        $users,
        new NativePasswordHasher(),
        new RepresentativePasswordPolicy(),
    );
}

function representativeUserPerson(int $id, ?Identification $identification): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Representative', null, 'Person', null),
        $identification,
        new DateTimeImmutable('1980-01-01', new DateTimeZone('UTC')),
        20,
        null,
        null,
        null,
        PersonStatus::Active,
        representativeUserToday(),
    );
}

function representativeUserRepresentative(int $id, int $personId): Representative
{
    return new Representative(
        new RepresentativeId($id),
        new RepresentativePersonId($personId),
        null,
        RepresentativeStatus::Active,
    );
}

function representativeUserUser(int $id, int $personId, string $login): User
{
    return new User(
        new UserId($id),
        new UserPersonId($personId),
        new LoginIdentifier($login),
        new PasswordHash((new NativePasswordHasher())->hash('stored-password')),
        UserStatus::Active,
    );
}

function representativeUserToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-10 12:00:00', new DateTimeZone('UTC'));
}
