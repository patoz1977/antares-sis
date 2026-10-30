<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\CanAccessAllReports;
use App\IdentityAccess\Application\UserCapabilityLookup;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserCapabilityLookup;
use Database\Seeders\AdminSeeder;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

require_once __DIR__ . '/../database/seeders/AdminSeeder.php';

function registerInstitutionalReportingAccessPhase2Tests(TestRunner $runner): void
{
    $runner->add('report access policy requires an active persisted User with the exact capability', function (): void {
        $active = reportingAccessUser(1, UserStatus::Active);
        $disabled = reportingAccessUser(2, UserStatus::Disabled);

        assertSameValue(true, reportingAccessPolicy($active, [1 => ['REPORTS_VIEW_ALL']])->handle(1));
        assertSameValue(false, reportingAccessPolicy($active, [])->handle(1));
        assertSameValue(false, reportingAccessPolicy($disabled, [2 => ['REPORTS_VIEW_ALL']])->handle(2));
        assertSameValue(false, reportingAccessPolicy(null, [1 => ['REPORTS_VIEW_ALL']])->handle(1));
        assertSameValue(false, reportingAccessPolicy($active, [1 => ['reports_view_all']])->handle(1));
        assertSameValue(false, reportingAccessPolicy($active, [2 => ['REPORTS_VIEW_ALL']])->handle(1));
        assertSameValue(false, reportingAccessPolicy($active, [1 => ['REPORTS_VIEW_ALL']])->handle(0));
    });

    $runner->add('PDO capability lookup is bounded by UserId and exact capability code', function (): void {
        $pdo = reportingCapabilityDatabase();
        $pdo->exec("INSERT INTO user_capabilities (user_id, capability_code) VALUES (1, 'REPORTS_VIEW_ALL')");
        $lookup = reportingCapabilityLookup($pdo);

        assertSameValue(true, $lookup->hasCapability(new UserId(1), 'REPORTS_VIEW_ALL'));
        assertSameValue(false, $lookup->hasCapability(new UserId(1), 'reports_view_all'));
        assertSameValue(false, $lookup->hasCapability(new UserId(2), 'REPORTS_VIEW_ALL'));
        assertSameValue(false, $lookup->hasCapability(new UserId(1), 'UNKNOWN'));
    });

    $runner->add('capability persistence rejects duplicate and orphan assignments', function (): void {
        $pdo = reportingCapabilityDatabase();
        $pdo->exec("INSERT INTO user_capabilities (user_id, capability_code) VALUES (1, 'REPORTS_VIEW_ALL')");

        assertThrows(
            fn () => $pdo->exec("INSERT INTO user_capabilities (user_id, capability_code) VALUES (1, 'REPORTS_VIEW_ALL')"),
            \PDOException::class,
        );
        assertThrows(
            fn () => $pdo->exec("INSERT INTO user_capabilities (user_id, capability_code) VALUES (999, 'REPORTS_VIEW_ALL')"),
            \PDOException::class,
        );
    });

    $runner->add('AdminSeeder reconciles existing Admin capability without changing User state', function (): void {
        $pdo = reportingCapabilityDatabase(true);
        $before = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $seeder = new AdminSeeder();

        $seeder->run($pdo);
        $seeder->run($pdo);

        $after = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assertSameValue($before, $after);
        assertSameValue(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM user_capabilities WHERE user_id = 1 AND capability_code = 'REPORTS_VIEW_ALL'"
            )->fetchColumn(),
        );
    });

    $runner->add('AdminSeeder creates fresh Admin with one reports capability idempotently', function (): void {
        $pdo = reportingCapabilityDatabase(false);
        $previousPerson = getenv('E0041_ADMIN_PERSON_ID');
        $previousPassword = getenv('E0041_ADMIN_INITIAL_PASSWORD');
        putenv('E0041_ADMIN_PERSON_ID=1');
        putenv('E0041_ADMIN_INITIAL_PASSWORD=temporary-test-password');

        try {
            $seeder = new AdminSeeder();
            $seeder->run($pdo);
            $created = $pdo->query(
                "SELECT id, person_id, status_id, login_identifier, normalized_login_identifier "
                . "FROM users WHERE normalized_login_identifier = 'admin'"
            )->fetch(PDO::FETCH_ASSOC);
            $seeder->run($pdo);

            assertSameValue('1', (string) $created['person_id']);
            assertSameValue('1', (string) $created['status_id']);
            assertSameValue('admin', $created['login_identifier']);
            assertSameValue('admin', $created['normalized_login_identifier']);
            assertSameValue(
                1,
                (int) $pdo->query('SELECT COUNT(*) FROM user_capabilities')->fetchColumn(),
            );
        } finally {
            restoreReportingEnvironment('E0041_ADMIN_PERSON_ID', $previousPerson);
            restoreReportingEnvironment('E0041_ADMIN_INITIAL_PASSWORD', $previousPassword);
        }
    });
}

/** @param array<int, list<string>> $capabilities */
function reportingAccessPolicy(?User $user, array $capabilities): CanAccessAllReports
{
    $repository = new class($user) implements UserRepository {
        public function __construct(private readonly ?User $user)
        {
        }

        public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
        {
            return null;
        }

        public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
        {
            return null;
        }

        public function findById(UserId $id): ?User
        {
            return $this->user?->id()?->value() === $id->value() ? $this->user : null;
        }

        public function findByPersonId(PersonId $personId): ?User
        {
            return null;
        }

        public function save(User $user): User
        {
            return $user;
        }
    };
    $lookup = new class($capabilities) implements UserCapabilityLookup {
        /** @param array<int, list<string>> $capabilities */
        public function __construct(private readonly array $capabilities)
        {
        }

        public function hasCapability(UserId $userId, string $capabilityCode): bool
        {
            return in_array($capabilityCode, $this->capabilities[$userId->value()] ?? [], true);
        }
    };

    return new CanAccessAllReports($repository, $lookup);
}

function reportingAccessUser(int $id, UserStatus $status): User
{
    return new User(
        new UserId($id),
        new PersonId($id),
        new LoginIdentifier('user-' . $id),
        new PasswordHash('test-password-hash'),
        $status,
    );
}

function reportingCapabilityDatabase(bool $withAdmin = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE persons (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
        . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
        . 'CREATE TABLE users ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL UNIQUE, status_id INTEGER NOT NULL, '
        . 'login_identifier TEXT NOT NULL, normalized_login_identifier TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, '
        . 'failed_login_attempts INTEGER NOT NULL DEFAULT 0, locked_at TEXT NULL, last_access_at TEXT NULL, '
        . 'created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NULL DEFAULT CURRENT_TIMESTAMP);'
        . 'CREATE TABLE user_capabilities ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, '
        . 'capability_code TEXT COLLATE BINARY NOT NULL, granted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE(user_id, capability_code), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT);'
    );
    $pdo->exec('INSERT INTO persons (id) VALUES (1), (2)');
    $pdo->exec("INSERT INTO status_types (id, code) VALUES (1, 'USER_STATUS')");
    $pdo->exec("INSERT INTO statuses (id, status_type_id, code) VALUES (1, 1, 'ACTIVE'), (2, 1, 'DISABLED')");

    if ($withAdmin) {
        $statement = $pdo->prepare(
            'INSERT INTO users '
            . '(id, person_id, status_id, login_identifier, normalized_login_identifier, password_hash, '
            . 'failed_login_attempts, locked_at, last_access_at, created_at, updated_at) '
            . 'VALUES (1, 1, 2, :login, :login, :password, 4, :lockedAt, :lastAccessAt, :createdAt, :updatedAt)'
        );
        $statement->execute([
            ':login' => 'admin',
            ':password' => 'preserved-hash',
            ':lockedAt' => '2026-10-01 10:00:00',
            ':lastAccessAt' => '2026-10-02 11:00:00',
            ':createdAt' => '2026-01-01 00:00:00',
            ':updatedAt' => '2026-10-02 11:00:00',
        ]);
    } else {
        $pdo->exec(
            "INSERT INTO users (id, person_id, status_id, login_identifier, normalized_login_identifier, password_hash) "
            . "VALUES (2, 2, 1, 'other', 'other', 'other-hash')"
        );
    }

    return $pdo;
}

function reportingCapabilityLookup(PDO $pdo): PdoUserCapabilityLookup
{
    $reflection = new ReflectionClass(PdoUserCapabilityLookup::class);
    $lookup = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoUserCapabilityLookup::class, 'connection');
    $property->setValue($lookup, $pdo);

    return $lookup;
}

function restoreReportingEnvironment(string $name, string|false $value): void
{
    if ($value === false) {
        putenv($name);

        return;
    }

    putenv($name . '=' . $value);
}
