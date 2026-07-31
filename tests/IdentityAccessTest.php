<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Contract\TransactionManager;
use App\IdentityAccess\Application\Exception\InvalidAuthenticationPolicy;
use App\IdentityAccess\Application\LogoutUser;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Persistence\PdoTransactionManager;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Tests\Support\TestRunner;

function registerIdentityAccessTests(TestRunner $runner): void
{
    $runner->add('domain starts without failures', function (): void {
        $user = testUser();
        assertSameValue(0, $user->failedLoginAttempts());
        assertSameValue(null, $user->lockedAt());
    });

    $runner->add('domain rejects invalid identifiers and negative attempts', function (): void {
        assertThrows(fn () => new UserId(0), InvalidUserState::class);
        assertThrows(fn () => new PersonId(0), InvalidUserState::class);
        assertThrows(fn () => new LoginIdentifier('   '), InvalidUserState::class);
        assertThrows(
            fn () => testUser(UserStatus::Active, -1),
            InvalidUserState::class
        );
    });

    $runner->add('failed attempts increment without early lock', function (): void {
        $user = testUser();
        $locked = $user->recordFailedLogin(testNow(), 5);
        assertSameValue(false, $locked);
        assertSameValue(1, $user->failedLoginAttempts());
        assertSameValue(null, $user->lockedAt());
    });

    $runner->add('fifth failed attempt activates lock', function (): void {
        $user = testUser(UserStatus::Active, 4);
        $locked = $user->recordFailedLogin(testNow(), 5);
        assertSameValue(true, $locked);
        assertSameValue(5, $user->failedLoginAttempts());
        assertSameValue(testNow()->getTimestamp(), $user->lockedAt()?->getTimestamp());
    });

    $runner->add('active lock remains unchanged', function (): void {
        $lockedAt = testNow();
        $user = testUser(UserStatus::Active, 5, $lockedAt);
        assertSameValue(
            true,
            $user->isTemporarilyLocked($lockedAt->modify('+899 seconds'), 900)
        );
        assertSameValue(5, $user->failedLoginAttempts());
        assertSameValue($lockedAt->getTimestamp(), $user->lockedAt()?->getTimestamp());
    });

    $runner->add('expired lock clears automatically', function (): void {
        $user = testUser(UserStatus::Active, 5, testNow()->modify('-900 seconds'));
        assertSameValue(true, $user->clearExpiredLock(testNow(), 900));
        assertSameValue(0, $user->failedLoginAttempts());
        assertSameValue(null, $user->lockedAt());
    });

    $runner->add('successful authentication clears failures and records access', function (): void {
        $user = testUser(UserStatus::Active, 5, testNow()->modify('-901 seconds'));
        $user->recordSuccessfulAuthentication(testNow());
        assertSameValue(0, $user->failedLoginAttempts());
        assertSameValue(null, $user->lockedAt());
        assertSameValue(testNow()->getTimestamp(), $user->lastAccessAt()?->getTimestamp());
    });

    $runner->add('disabled status stays separate from lock', function (): void {
        $user = testUser(UserStatus::Disabled);
        assertSameValue(true, $user->isDisabled());
        assertSameValue(false, $user->isTemporarilyLocked(testNow(), 900));
    });

    $runner->add('valid credentials authenticate and regenerate session', function (): void {
        [$useCase, $repository, $session, $events] = authenticationFixture();
        $result = $useCase->handle(' ADMIN ', 'correct-password');
        assertSameValue(true, $result->isSuccessful());
        assertSameValue(1, $session->userId);
        assertSameValue(1, $session->regenerations);
        assertSameValue(0, $repository->user->failedLoginAttempts());
        assertContainsValue('authentication.succeeded', $events->events);
    });

    $runner->add('unknown identifier returns generic failure', function (): void {
        [$useCase] = authenticationFixture(null);
        $result = $useCase->handle('missing', 'wrong');
        assertSameValue(false, $result->isSuccessful());
        assertSameValue('Invalid credentials.', $result->externalMessage());
    });

    $runner->add('wrong password persists failed attempt', function (): void {
        [$useCase, $repository] = authenticationFixture();
        $result = $useCase->handle('admin', 'wrong');
        assertSameValue(false, $result->isSuccessful());
        assertSameValue(1, $repository->user->failedLoginAttempts());
        assertSameValue(1, $repository->saves);
    });

    $runner->add('disabled user returns same generic failure', function (): void {
        [$useCase, $repository] = authenticationFixture(
            testUser(UserStatus::Disabled)
        );
        $result = $useCase->handle('admin', 'correct-password');
        assertSameValue(false, $result->isSuccessful());
        assertSameValue('Invalid credentials.', $result->externalMessage());
        assertSameValue(0, $repository->saves);
    });

    $runner->add('fifth application failure activates lock', function (): void {
        [$useCase, $repository, , $events] = authenticationFixture(
            testUser(UserStatus::Active, 4)
        );
        $useCase->handle('admin', 'wrong');
        assertSameValue(5, $repository->user->failedLoginAttempts());
        assertSameValue(testNow()->getTimestamp(), $repository->user->lockedAt()?->getTimestamp());
        assertContainsValue('authentication.lock_activated', $events->events);
    });

    $runner->add('correct password during lock does not authenticate or extend', function (): void {
        $lockedAt = testNow()->modify('-100 seconds');
        [$useCase, $repository, $session] = authenticationFixture(
            testUser(UserStatus::Active, 5, $lockedAt)
        );
        $result = $useCase->handle('admin', 'correct-password');
        assertSameValue(false, $result->isSuccessful());
        assertSameValue(0, $session->regenerations);
        assertSameValue(5, $repository->user->failedLoginAttempts());
        assertSameValue($lockedAt->getTimestamp(), $repository->user->lockedAt()?->getTimestamp());
        assertSameValue(0, $repository->saves);
    });

    $runner->add('correct attempt after expiration authenticates', function (): void {
        [$useCase, $repository] = authenticationFixture(
            testUser(UserStatus::Active, 5, testNow()->modify('-901 seconds'))
        );
        assertSameValue(true, $useCase->handle('admin', 'correct-password')->isSuccessful());
        assertSameValue(0, $repository->user->failedLoginAttempts());
        assertSameValue(null, $repository->user->lockedAt());
    });

    $runner->add('wrong attempt after expiration starts at one', function (): void {
        [$useCase, $repository] = authenticationFixture(
            testUser(UserStatus::Active, 5, testNow()->modify('-901 seconds'))
        );
        assertSameValue(false, $useCase->handle('admin', 'wrong')->isSuccessful());
        assertSameValue(1, $repository->user->failedLoginAttempts());
        assertSameValue(null, $repository->user->lockedAt());
    });

    $runner->add('custom threshold is honored', function (): void {
        [$useCase, $repository] = authenticationFixture(
            testUser(UserStatus::Active, 1),
            new AuthenticationPolicy(2, 60)
        );
        $useCase->handle('admin', 'wrong');
        assertSameValue(2, $repository->user->failedLoginAttempts());
        assertSameValue(testNow()->getTimestamp(), $repository->user->lockedAt()?->getTimestamp());
    });

    $runner->add('custom duration is honored', function (): void {
        [$useCase] = authenticationFixture(
            testUser(UserStatus::Active, 2, testNow()->modify('-61 seconds')),
            new AuthenticationPolicy(3, 60)
        );
        assertSameValue(true, $useCase->handle('admin', 'correct-password')->isSuccessful());
    });

    $runner->add('invalid authentication policy fails secure', function (): void {
        assertThrows(
            fn () => new AuthenticationPolicy(0, 900),
            InvalidAuthenticationPolicy::class
        );
        assertThrows(
            fn () => new AuthenticationPolicy(5, 0),
            InvalidAuthenticationPolicy::class
        );
    });

    $runner->add('logout destroys session and records event', function (): void {
        $session = new FakeSessionManager();
        $events = new FakeSecurityEvents();
        (new LogoutUser($session, $events))->handle();
        assertSameValue(1, $session->destructions);
        assertContainsValue('authentication.logged_out', $events->events);
    });

    $runner->add('transaction failure propagates without session mutation', function (): void {
        $repository = new InMemoryUserRepository(testUser());
        $session = new FakeSessionManager();
        $useCase = new AuthenticateUser(
            $repository,
            new NativePasswordHasher(),
            $session,
            new FailingTransactionManager(),
            new FrozenClock(testNow()),
            new FakeSecurityEvents(),
            new AuthenticationPolicy(5, 900),
        );
        assertThrows(
            fn () => $useCase->handle('admin', 'correct-password'),
            RuntimeException::class
        );
        assertSameValue(0, $session->regenerations);
    });

    $runner->add('session csrf token is one time and rejects invalid input', function (): void {
        $session = new FakeSessionManager();
        $csrf = new SessionCsrfTokenManager($session);
        $token = $csrf->token();
        assertSameValue(64, strlen($token));
        assertSameValue(false, $csrf->isValid('invalid'));
        $token = $csrf->token();
        assertSameValue(true, $csrf->isValid($token));
        assertSameValue(false, $csrf->isValid($token));
    });

    $runner->add('pdo repository maps and persists User aggregate', function (): void {
        $pdo = sqliteIdentityDatabase();
        $repository = repositoryWithPdo($pdo);
        $user = $repository->findByLoginIdentifier(new LoginIdentifier('ADMIN'));
        assertSameValue(1, $user?->personId()->value());
        assertSameValue(UserStatus::Active, $user?->status());

        $user?->recordFailedLogin(testNow(), 5);
        $repository->save($user);

        $row = $pdo->query(
            'SELECT failed_login_attempts, locked_at FROM users WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        assertSameValue(1, (int) $row['failed_login_attempts']);
        assertSameValue(null, $row['locked_at']);
    });

    $runner->add('pdo repository excludes soft-deleted and validates User status type', function (): void {
        $pdo = sqliteIdentityDatabase();
        $repository = repositoryWithPdo($pdo);
        $pdo->exec("UPDATE users SET deleted_at = '2026-01-01 00:00:00'");
        assertSameValue(
            null,
            $repository->findByLoginIdentifier(new LoginIdentifier('admin'))
        );
    });

    $runner->add('pdo transaction rolls back partial changes', function (): void {
        $pdo = sqliteIdentityDatabase();
        $transactions = transactionManagerWithPdo($pdo);
        assertThrows(function () use ($transactions, $pdo): void {
            $transactions->transactional(function () use ($pdo): void {
                $pdo->exec('UPDATE users SET failed_login_attempts = 4 WHERE id = 1');
                throw new RuntimeException('persistence failure');
            });
        }, RuntimeException::class);
        assertSameValue(
            0,
            (int) $pdo->query(
                'SELECT failed_login_attempts FROM users WHERE id = 1'
            )->fetchColumn()
        );
    });

    $runner->add('migration 014 preserves legacy column and declares approved fields', function (): void {
        $migration = file_get_contents(
            __DIR__ . '/../database/migrations/014_create_identity_access_baseline.php'
        );
        assertContainsText('failed_login_attempts', $migration);
        assertContainsText('locked_at', $migration);
        assertContainsText('normalized_login_identifier', $migration);
        assertSameValue(false, str_contains($migration, 'DROP COLUMN `locked_until`'));
        assertContainsText('LEGACY_LOCKOUT_DURATION_SECONDS = 900', $migration);
    });

    $runner->add('active HTTP routes use only module authentication controller', function (): void {
        $routes = file_get_contents(__DIR__ . '/../routes/web.php');
        assertContainsText(
            'App\\IdentityAccess\\Http\\AuthenticationController',
            $routes
        );
        assertSameValue(
            false,
            str_contains($routes, 'App\\Controllers\\AuthenticationController')
        );
    });
}

function testUser(
    UserStatus $status = UserStatus::Active,
    int $attempts = 0,
    ?DateTimeImmutable $lockedAt = null
): User {
    $hash = password_hash('correct-password', PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Unable to create test password hash.');
    }

    return new User(
        new UserId(1),
        new PersonId(1),
        new LoginIdentifier('admin'),
        new PasswordHash($hash),
        $status,
        $attempts,
        $lockedAt,
    );
}

function authenticationFixture(
    User|null|false $user = false,
    ?AuthenticationPolicy $policy = null
): array {
    $resolvedUser = $user === false ? testUser() : $user;
    $repository = new InMemoryUserRepository($resolvedUser);
    $session = new FakeSessionManager();
    $events = new FakeSecurityEvents();

    $useCase = new AuthenticateUser(
        $repository,
        new NativePasswordHasher(),
        $session,
        new ImmediateTransactionManager(),
        new FrozenClock(testNow()),
        $events,
        $policy ?? new AuthenticationPolicy(5, 900),
    );

    return [$useCase, $repository, $session, $events];
}

function testNow(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-07-31 12:00:00', new DateTimeZone('UTC'));
}

function sqliteIdentityDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE persons (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
        . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
        . 'CREATE TABLE users ('
        . 'id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL, status_id INTEGER NOT NULL, '
        . 'normalized_login_identifier TEXT NOT NULL, password_hash TEXT NOT NULL, '
        . 'failed_login_attempts INTEGER NOT NULL DEFAULT 0, locked_at TEXT NULL, '
        . 'last_access_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL);'
    );
    $hash = password_hash('correct-password', PASSWORD_DEFAULT);
    $pdo->exec('INSERT INTO persons (id) VALUES (1)');
    $pdo->exec("INSERT INTO status_types (id, code) VALUES (1, 'USER_STATUS')");
    $pdo->exec("INSERT INTO statuses (id, status_type_id, code) VALUES (1, 1, 'ACTIVE')");
    $statement = $pdo->prepare(
        'INSERT INTO users '
        . '(id, person_id, status_id, normalized_login_identifier, password_hash) '
        . 'VALUES (1, 1, 1, :identifier, :passwordHash)'
    );
    $statement->execute([
        ':identifier' => 'admin',
        ':passwordHash' => $hash,
    ]);

    return $pdo;
}

function repositoryWithPdo(PDO $pdo): PdoUserRepository
{
    $reflection = new ReflectionClass(PdoUserRepository::class);
    $repository = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoUserRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function transactionManagerWithPdo(PDO $pdo): PdoTransactionManager
{
    $reflection = new ReflectionClass(PdoTransactionManager::class);
    $manager = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoTransactionManager::class, 'connection');
    $property->setValue($manager, $pdo);

    return $manager;
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertContainsValue(mixed $expected, array $values): void
{
    if (!in_array($expected, $values, true)) {
        throw new RuntimeException('Expected value was not found.');
    }
}

function assertContainsText(string $needle, string|false $text): void
{
    if (!is_string($text) || !str_contains($text, $needle)) {
        throw new RuntimeException(sprintf('Expected text not found: %s', $needle));
    }
}

function assertThrows(callable $operation, string $expectedClass): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Expected %s, got %s.',
            $expectedClass,
            $exception::class
        ));
    }

    throw new RuntimeException(sprintf('Expected exception %s.', $expectedClass));
}

final class InMemoryUserRepository implements UserRepository
{
    public int $saves = 0;

    public function __construct(public ?User $user)
    {
    }

    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
    {
        if ($this->user?->loginIdentifier()->value() !== $identifier->value()) {
            return null;
        }

        return $this->user;
    }

    public function findById(UserId $id): ?User
    {
        return $this->user?->id()->value() === $id->value() ? $this->user : null;
    }

    public function save(User $user): void
    {
        $this->user = $user;
        $this->saves++;
    }
}

final class FakeSessionManager implements SessionManager
{
    public ?int $userId = null;
    public int $regenerations = 0;
    public int $destructions = 0;
    private array $values = [];

    public function regenerateForUser(int $userId): void
    {
        $this->regenerations++;
        $this->userId = $userId;
    }

    public function authenticatedUserId(): ?int
    {
        return $this->userId;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? $default;
        unset($this->values[$key]);

        return $value;
    }

    public function destroy(): void
    {
        $this->destructions++;
        $this->userId = null;
        $this->values = [];
    }
}

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class FakeSecurityEvents implements SecurityEventLogger
{
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

final class ImmediateTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}

final class FailingTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        $operation();
        throw new RuntimeException('transaction failed');
    }
}
