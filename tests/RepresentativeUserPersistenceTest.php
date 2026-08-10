<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativeUserPersistenceTests(TestRunner $runner): void
{
    $runner->add('pdo User lookup by Person returns existing and missing rows', function (): void {
        $repository = repositoryWithPdo(sqliteIdentityDatabase());

        assertSameValue(1, $repository->findByPersonId(new PersonId(1))?->id()?->value());
        assertSameValue(null, $repository->findByPersonId(new PersonId(999)));
    });

    $runner->add('pdo User repository exposes per-Person and normalized-login physical uniqueness', function (): void {
        $pdo = sqliteIdentityDatabase();
        $pdo->exec('INSERT INTO persons (id) VALUES (2)');
        $repository = repositoryWithPdo($pdo);

        assertThrows(
            fn () => $repository->save(representativePersistenceUser(null, 1, 'other-login')),
            PDOException::class,
        );
        assertThrows(
            fn () => $repository->save(representativePersistenceUser(null, 2, 'ADMIN')),
            PDOException::class,
        );
    });

    $runner->add('pdo User update preserves Person and complete authentication state', function (): void {
        $pdo = sqliteIdentityDatabase();
        $repository = repositoryWithPdo($pdo);
        $lockedAt = new DateTimeImmutable('2026-08-10 10:11:12', new DateTimeZone('UTC'));
        $lastAccessAt = new DateTimeImmutable('2026-08-09 08:09:10', new DateTimeZone('UTC'));
        $user = new User(
            new UserId(1),
            new PersonId(1),
            new LoginIdentifier('new-document'),
            new PasswordHash('new-hash'),
            UserStatus::Active,
            5,
            $lockedAt,
            $lastAccessAt,
        );

        $persisted = $repository->save($user);
        $row = $pdo->query(
            'SELECT person_id, login_identifier, normalized_login_identifier, password_hash, '
            . 'failed_login_attempts, locked_at, last_access_at FROM users WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        assertSameValue(1, $persisted->personId()->value());
        assertSameValue('new-document', $persisted->loginIdentifier()->value());
        assertSameValue('new-hash', $persisted->passwordHash()->value());
        assertSameValue(5, $persisted->failedLoginAttempts());
        assertSameValue($lockedAt->getTimestamp(), $persisted->lockedAt()?->getTimestamp());
        assertSameValue($lastAccessAt->getTimestamp(), $persisted->lastAccessAt()?->getTimestamp());
        assertSameValue(1, (int) ($row['person_id'] ?? 0));
        assertSameValue('new-document', $row['login_identifier'] ?? null);
        assertSameValue('new-document', $row['normalized_login_identifier'] ?? null);
        assertSameValue('new-hash', $row['password_hash'] ?? null);
    });

    $runner->add('pdo User repository rejects disappearing update target', function (): void {
        $pdo = sqliteIdentityDatabase();
        $repository = repositoryWithPdo($pdo);
        $user = $repository->findById(new UserId(1));
        if ($user === null) {
            throw new RuntimeException('User fixture was not found.');
        }
        $pdo->exec('DELETE FROM users WHERE id = 1');

        assertThrows(fn () => $repository->save($user), RuntimeException::class);
    });

    $runner->add('pdo User repository participates in outer transaction without owning it', function (): void {
        $pdo = sqliteIdentityDatabase();
        $pdo->exec('INSERT INTO persons (id) VALUES (2)');
        $repository = repositoryWithPdo($pdo);
        $pdo->beginTransaction();
        try {
            $persisted = $repository->save(representativePersistenceUser(null, 2, 'outer-user'));
            assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
            assertSameValue(true, $pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
        assertSameValue(null, $repository->findByPersonId(new PersonId(2)));
    });

    $runner->add('pdo User repository uses fixed prepared SQL without manual identity generation', function (): void {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Infrastructure/Persistence/PdoUserRepository.php'
        );

        assertSameValue(true, str_contains($source, 'lastInsertId()'));
        assertSameValue(false, str_contains($source, 'MAX('));
        assertSameValue(false, str_contains($source, 'beginTransaction'));
        assertSameValue(false, str_contains($source, 'commit('));
        assertSameValue(false, str_contains($source, 'rollBack('));
        assertSameValue(false, str_contains($source, 'UPDATE persons'));
    });
}

function representativePersistenceUser(
    ?UserId $id,
    int $personId,
    string $loginIdentifier,
): User {
    return new User(
        $id,
        new PersonId($personId),
        new LoginIdentifier($loginIdentifier),
        new PasswordHash('persistence-hash'),
        UserStatus::Active,
    );
}
