<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\CanAccessAllReports;
use App\IdentityAccess\Application\DisableInstitutionalReportingUser;
use App\IdentityAccess\Application\Dto\ProvisionInstitutionalReportingUserInput;
use App\IdentityAccess\Application\Dto\InstitutionalReportingAccessState;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Application\Exception\InstitutionalReportingLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\InstitutionalReportingReservedLoginIdentifier;
use App\IdentityAccess\Application\Exception\InvalidInstitutionalReportingPassword;
use App\IdentityAccess\Application\GetInstitutionalReportingAccessState;
use App\IdentityAccess\Application\GrantInstitutionalReportingAccessToExistingUser;
use App\IdentityAccess\Application\InstitutionalReportingCapabilityGrant;
use App\IdentityAccess\Application\InstitutionalReportingPersonEligibility;
use App\IdentityAccess\Application\IsDedicatedInstitutionalReportingUser;
use App\IdentityAccess\Application\LockingUserCapabilityLookup;
use App\IdentityAccess\Application\ProvisionInstitutionalReportingUser;
use App\IdentityAccess\Application\ReactivateInstitutionalReportingUser;
use App\IdentityAccess\Application\Security\InstitutionalReportingPasswordPolicy;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Persistence\PdoInstitutionalReportingCapabilityGrant;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserCapabilityLookup;
use App\IdentityAccess\Http\InstitutionalReportingAccessController;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Domain\RepresentativeStatus;
use App\Shared\Application\Exception\DedicatedInstitutionalReportingRoleConflict;
use App\Student\Application\CreateStudent;
use App\Student\Application\Dto\CreateStudentInput;
use App\Student\Domain\StudentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\TestRunner;

function registerInstitutionalReportingAccessPhase4Tests(TestRunner $runner): void
{
    $runner->add('Phase 4 User lifecycle changes only status and is idempotent', function (): void {
        $lockedAt = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('UTC'));
        $lastAccess = new DateTimeImmutable('2026-10-02 11:00:00', new DateTimeZone('UTC'));
        $user = phase4User(10, 20, 'reporter', UserStatus::Active, 4, $lockedAt, $lastAccess);
        $before = phase4UserSnapshot($user);

        assertSameValue(true, $user->disable());
        assertSameValue(false, $user->disable());
        assertSameValue(UserStatus::Disabled, $user->status());
        assertSameValue(true, $user->reactivate());
        assertSameValue(false, $user->reactivate());
        assertSameValue($before, phase4UserSnapshot($user));
    });

    $runner->add('Phase 4 password rule is shared only by approved profile policies', function (): void {
        $reporting = new InstitutionalReportingPasswordPolicy();
        $representative = new RepresentativePasswordPolicy();
        assertThrows(fn () => $reporting->assertValid('1234'), \RuntimeException::class);
        assertThrows(fn () => $representative->assertValid('1234'), \RuntimeException::class);
        $reporting->assertValid('12345');
        $representative->assertValid('abcde');

        $ruleSource = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Application/Security/FiveCharacterPasswordRule.php'
        );
        assertSameValue(false, str_contains($ruleSource, 'Universal'));
    });

    $runner->add('Phase 4 dedicated classifier excludes exact Admin without changing report authorization', function (): void {
        $users = new InMemoryRepresentativeUserRepository();
        $capabilities = new InMemoryInstitutionalReportingCapabilities();
        $reporter = phase4User(1, 10, 'reporter');
        $admin = phase4User(2, 20, 'admin');
        $ordinary = phase4User(3, 30, 'ordinary');
        $users->seed($reporter);
        $users->seed($admin);
        $users->seed($ordinary);
        $capabilities->grant(new UserId(1));
        $capabilities->grant(new UserId(2));
        $policy = new IsDedicatedInstitutionalReportingUser($users, $capabilities);

        assertSameValue(true, $policy->handle($reporter));
        assertSameValue(false, $policy->handle($admin));
        assertSameValue(false, $policy->handle($ordinary));
        assertSameValue(true, (new CanAccessAllReports($users, $capabilities))->handle(2));
    });

    $runner->add('Phase 4 dedicated guard uses current locking reads after Person serialization', function (): void {
        $users = new InMemoryRepresentativeUserRepository();
        $capabilities = new InMemoryInstitutionalReportingCapabilities();
        $user = phase4User(4, 40, 'current-reporter');
        $users->seed($user);
        $capabilities->grant(new UserId(4));
        $capabilities->makeOrdinarySnapshotStale();
        $policy = new IsDedicatedInstitutionalReportingUser($users, $capabilities);

        assertSameValue(false, $policy->handle($user));
        assertSameValue(true, $policy->isAssignedToPerson(40));
        assertSameValue(1, $users->lockingPersonReads());
        assertSameValue(1, $capabilities->lockingReads);

        $disabledUsers = new InMemoryRepresentativeUserRepository();
        $disabledCapabilities = new InMemoryInstitutionalReportingCapabilities();
        $disabledUsers->seed(phase4User(5, 50, 'disabled-current', UserStatus::Disabled));
        $disabledCapabilities->grant(new UserId(5));
        assertSameValue(true, (new IsDedicatedInstitutionalReportingUser(
            $disabledUsers,
            $disabledCapabilities,
        ))->isAssignedToPerson(50));
    });

    $runner->add('Phase 4 provisions one User and capability atomically through approved boundaries', function (): void {
        $fixture = phase4Fixture(100);
        $events = new FakeSecurityEvents();
        $useCase = new ProvisionInstitutionalReportingUser(
            new ImmediateTransactionManager(),
            $fixture['eligibility'],
            $fixture['users'],
            new InstitutionalReportingPasswordPolicy(),
            new NativePasswordHasher(),
            $fixture['capabilities'],
            $events,
        );
        $result = $useCase->handle(new ProvisionInstitutionalReportingUserInput(
            100,
            '  REPORTER.ONE  ',
            'abcde',
        ));

        assertSameValue(100, $result->personId);
        assertSameValue('reporter.one', $result->loginIdentifier);
        assertSameValue(UserStatus::Active, $result->status);
        assertSameValue(true, $fixture['capabilities']->hasCapability(
            new UserId($result->userId),
            CanAccessAllReports::CAPABILITY,
        ));
        assertSameValue(['institutional_reporting.provisioned'], $events->events);
        $persisted = $fixture['users']->findByPersonId(new UserPersonId(100));
        assertSameValue(true, password_verify('abcde', $persisted?->passwordHash()->value() ?? ''));
        assertSameValue(false, array_key_exists('password', get_object_vars($result)));
        assertSameValue(false, array_key_exists('passwordHash', get_object_vars($result)));
        assertSameValue(1, $fixture['representatives']->lockingPersonReads());
        assertSameValue(1, $fixture['students']->lockingPersonReads());
    });

    $runner->add('Phase 4 state query classifies every eligibility and lifecycle state safely', function (): void {
        $noUser = phase4Fixture(110);
        assertSameValue(InstitutionalReportingAccessState::ELIGIBLE_NO_USER, $noUser['state']->handle(110)->state);

        $ordinary = phase4Fixture(111);
        $ordinary['users']->seed(phase4User(21, 111, 'ordinary'));
        assertSameValue(InstitutionalReportingAccessState::ELIGIBLE_ACTIVE_USER, $ordinary['state']->handle(111)->state);

        $dedicated = phase4Fixture(112);
        $dedicated['users']->seed(phase4User(22, 112, 'dedicated'));
        $dedicated['capabilities']->grant(new UserId(22));
        assertSameValue(InstitutionalReportingAccessState::ACTIVE_DEDICATED, $dedicated['state']->handle(112)->state);

        $disabled = phase4Fixture(113);
        $disabled['users']->seed(phase4User(23, 113, 'disabled', UserStatus::Disabled));
        $disabled['capabilities']->grant(new UserId(23));
        assertSameValue(InstitutionalReportingAccessState::DISABLED_DEDICATED, $disabled['state']->handle(113)->state);

        $representative = phase4Fixture(114);
        $representative['representatives']->seed(representativeUserRepresentative(214, 114));
        assertSameValue(
            InstitutionalReportingAccessState::INELIGIBLE_REPRESENTATIVE,
            $representative['state']->handle(114)->state,
        );

        $student = phase4Fixture(115);
        $student['students']->seed(phase4Student(315, 115));
        assertSameValue(InstitutionalReportingAccessState::INELIGIBLE_STUDENT, $student['state']->handle(115)->state);

        $dual = phase4Fixture(116);
        $dual['representatives']->seed(representativeUserRepresentative(216, 116));
        $dual['students']->seed(phase4Student(316, 116));
        assertSameValue(InstitutionalReportingAccessState::INELIGIBLE_MULTIPLE_ROLES, $dual['state']->handle(116)->state);

        $admin = phase4Fixture(117);
        $admin['users']->seed(phase4User(27, 117, 'admin'));
        $admin['capabilities']->grant(new UserId(27));
        assertSameValue(InstitutionalReportingAccessState::EXACT_ADMIN, $admin['state']->handle(117)->state);
        assertThrows(fn () => $admin['state']->handle(999), InstitutionalReportingAccessConflict::class);
    });

    $runner->add('Phase 4 new User provisioning rejects eligibility login and password conflicts', function (): void {
        $fixture = phase4Fixture(120);
        $fixture['users']->seed(phase4User(30, 999, 'occupied'));
        $events = new FakeSecurityEvents();
        $useCase = new ProvisionInstitutionalReportingUser(
            new ImmediateTransactionManager(),
            $fixture['eligibility'],
            $fixture['users'],
            new InstitutionalReportingPasswordPolicy(),
            new NativePasswordHasher(),
            $fixture['capabilities'],
            $events,
        );
        assertThrows(
            fn () => $useCase->handle(new ProvisionInstitutionalReportingUserInput(120, 'occupied', 'abcde')),
            InstitutionalReportingLoginIdentifierAlreadyUsed::class,
        );
        assertThrows(
            fn () => $useCase->handle(new ProvisionInstitutionalReportingUserInput(120, 'new-login', '1234')),
            InvalidInstitutionalReportingPassword::class,
        );
        $fixture['representatives']->seed(representativeUserRepresentative(220, 120));
        assertThrows(
            fn () => $useCase->handle(new ProvisionInstitutionalReportingUserInput(120, 'new-login', 'abcde')),
            InstitutionalReportingAccessConflict::class,
        );
        assertSameValue([], $events->events);
    });

    $runner->add('Phase 4 new User provisioning reserves normalized exact Admin before persistence', function (): void {
        foreach (['admin', ' ADMIN ', 'AdMiN'] as $reservedLogin) {
            $fixture = phase4Fixture(127);
            $events = new FakeSecurityEvents();
            $useCase = new ProvisionInstitutionalReportingUser(
                new ImmediateTransactionManager(),
                $fixture['eligibility'],
                $fixture['users'],
                new InstitutionalReportingPasswordPolicy(),
                new NativePasswordHasher(),
                $fixture['capabilities'],
                $events,
            );

            assertThrows(
                fn () => $useCase->handle(new ProvisionInstitutionalReportingUserInput(
                    127,
                    $reservedLogin,
                    'abcde',
                )),
                InstitutionalReportingReservedLoginIdentifier::class,
            );
            assertSameValue(0, $fixture['users']->saveCalls());
            assertSameValue([], $events->events);
        }

        $controllerSource = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/InstitutionalReportingAccessController.php'
        );
        assertSameValue(true, str_contains(
            $controllerSource,
            'InstitutionalReportingLoginIdentifierAlreadyUsed|InstitutionalReportingReservedLoginIdentifier',
        ));
        assertSameValue(true, str_contains(
            $controllerSource,
            'Ese identificador de acceso ya está en uso.',
        ));
        assertSameValue(false, str_contains(
            $controllerSource,
            'Legacy Admin LoginIdentifier is reserved.',
        ));
    });

    $runner->add('Phase 4 existing grant rejects disabled and exact Admin while preserving existing state', function (): void {
        foreach ([
            phase4User(41, 121, 'disabled', UserStatus::Disabled, 4),
            phase4User(42, 122, 'admin', UserStatus::Active, 3),
        ] as $user) {
            $fixture = phase4Fixture($user->personId()->value());
            $fixture['users']->seed($user);
            $events = new FakeSecurityEvents();
            $before = phase4UserSnapshot($user);
            $useCase = new GrantInstitutionalReportingAccessToExistingUser(
                new ImmediateTransactionManager(),
                $fixture['eligibility'],
                $fixture['users'],
                new IsDedicatedInstitutionalReportingUser($fixture['users'], $fixture['capabilities']),
                $fixture['capabilities'],
                $events,
            );
            assertThrows(
                fn () => $useCase->handle($user->personId()->value()),
                InstitutionalReportingAccessConflict::class,
            );
            assertSameValue($before, phase4UserSnapshot(
                $fixture['users']->findById($user->id()) ?? throw new \RuntimeException('User missing.')
            ));
            assertSameValue([], $events->events);
        }
    });

    $runner->add('Phase 4 reactivation requires retained capability and renewed role eligibility', function (): void {
        $withoutCapability = phase4Fixture(123);
        $withoutCapability['users']->seed(phase4User(43, 123, 'missing-capability', UserStatus::Disabled));
        $classifier = new IsDedicatedInstitutionalReportingUser(
            $withoutCapability['users'],
            $withoutCapability['capabilities'],
        );
        assertThrows(
            fn () => (new ReactivateInstitutionalReportingUser(
                new ImmediateTransactionManager(),
                $withoutCapability['eligibility'],
                $withoutCapability['users'],
                $classifier,
                new FakeSecurityEvents(),
            ))->handle(123),
            InstitutionalReportingAccessConflict::class,
        );

        foreach (['representative', 'student'] as $role) {
            $personId = $role === 'representative' ? 124 : 125;
            $fixture = phase4Fixture($personId);
            $fixture['users']->seed(phase4User($personId, $personId, 'disabled-' . $role, UserStatus::Disabled));
            $fixture['capabilities']->grant(new UserId($personId));
            if ($role === 'representative') {
                $fixture['representatives']->seed(representativeUserRepresentative(224, $personId));
            } else {
                $fixture['students']->seed(phase4Student(325, $personId));
            }
            assertThrows(
                fn () => (new ReactivateInstitutionalReportingUser(
                    new ImmediateTransactionManager(),
                    $fixture['eligibility'],
                    $fixture['users'],
                    new IsDedicatedInstitutionalReportingUser($fixture['users'], $fixture['capabilities']),
                    new FakeSecurityEvents(),
                ))->handle($personId),
                InstitutionalReportingAccessConflict::class,
            );
        }
    });

    $runner->add('Phase 4 success events occur only after transaction completion', function (): void {
        $fixture = phase4Fixture(126);
        $events = new FakeSecurityEvents();
        $useCase = new ProvisionInstitutionalReportingUser(
            new FailingTransactionManager(),
            $fixture['eligibility'],
            $fixture['users'],
            new InstitutionalReportingPasswordPolicy(),
            new NativePasswordHasher(),
            $fixture['capabilities'],
            $events,
        );
        assertThrows(
            fn () => $useCase->handle(new ProvisionInstitutionalReportingUserInput(126, 'rollback', 'abcde')),
            \RuntimeException::class,
        );
        assertSameValue([], $events->events);
    });

    $runner->add('Phase 4 grant and lifecycle are idempotent and preserve capability', function (): void {
        $fixture = phase4Fixture(101);
        $user = phase4User(11, 101, 'existing', UserStatus::Active, 3);
        $fixture['users']->seed($user);
        $events = new FakeSecurityEvents();
        $classifier = new IsDedicatedInstitutionalReportingUser(
            $fixture['users'],
            $fixture['capabilities'],
        );
        $grant = new GrantInstitutionalReportingAccessToExistingUser(
            new ImmediateTransactionManager(),
            $fixture['eligibility'],
            $fixture['users'],
            $classifier,
            $fixture['capabilities'],
            $events,
        );
        assertSameValue(true, $grant->handle(101));
        assertSameValue(false, $grant->handle(101));

        $disable = new DisableInstitutionalReportingUser(
            new ImmediateTransactionManager(),
            $fixture['persons'],
            $fixture['users'],
            $classifier,
            $events,
        );
        assertSameValue(true, $disable->handle(101));
        assertSameValue(false, $disable->handle(101));

        $reactivate = new ReactivateInstitutionalReportingUser(
            new ImmediateTransactionManager(),
            $fixture['eligibility'],
            $fixture['users'],
            $classifier,
            $events,
        );
        assertSameValue(true, $reactivate->handle(101));
        assertSameValue(false, $reactivate->handle(101));
        assertSameValue([
            'institutional_reporting.access_granted',
            'institutional_reporting.disabled',
            'institutional_reporting.reactivated',
        ], $events->events);
        assertSameValue(true, $fixture['capabilities']->hasCapability(
            new UserId(11),
            CanAccessAllReports::CAPABILITY,
        ));
        assertSameValue(3, $fixture['users']->findById(new UserId(11))?->failedLoginAttempts());
    });

    $runner->add('Phase 4 future role guard blocks dedicated Person and does not block Admin', function (): void {
        $fixture = phase4Fixture(102, true);
        $dedicated = phase4User(12, 102, 'reporter');
        $fixture['users']->seed($dedicated);
        $fixture['capabilities']->grant(new UserId(12));
        $classifier = new IsDedicatedInstitutionalReportingUser(
            $fixture['users'],
            $fixture['capabilities'],
        );
        $createRepresentative = new CreateRepresentative(
            $fixture['persons'],
            $fixture['representatives'],
            new ImmediateTransactionManager(),
            $classifier,
        );
        $createStudent = new CreateStudent(
            $fixture['persons'],
            $fixture['students'],
            new ImmediateTransactionManager(),
            $classifier,
        );
        assertThrows(
            fn () => $createRepresentative->handle(new CreateRepresentativeInput(
                102, null, null, null, null, null, RepresentativeStatus::Active,
            )),
            DedicatedInstitutionalReportingRoleConflict::class,
        );
        assertThrows(
            fn () => $createStudent->handle(new CreateStudentInput(
                102,
                'STU-102',
                new DateTimeImmutable('2020-01-01'),
                StudentStatus::Active,
            ), new DateTimeImmutable('2026-10-30')),
            DedicatedInstitutionalReportingRoleConflict::class,
        );

        $adminFixture = phase4Fixture(103, true);
        $adminFixture['users']->seed(phase4User(13, 103, 'admin'));
        $adminFixture['capabilities']->grant(new UserId(13));
        $adminClassifier = new IsDedicatedInstitutionalReportingUser(
            $adminFixture['users'],
            $adminFixture['capabilities'],
        );
        $representative = (new CreateRepresentative(
            $adminFixture['persons'],
            $adminFixture['representatives'],
            new ImmediateTransactionManager(),
            $adminClassifier,
        ))->handle(new CreateRepresentativeInput(
            103, null, null, null, null, null, RepresentativeStatus::Active,
        ));
        assertSameValue(103, $representative->personId);

        $representativeSource = (string) file_get_contents(
            dirname(__DIR__) . '/app/Representative/Application/CreateRepresentative.php'
        );
        $studentSource = (string) file_get_contents(
            dirname(__DIR__) . '/app/Student/Application/CreateStudent.php'
        );
        foreach ([$representativeSource, $studentSource] as $source) {
            assertSameValue(false, str_contains($source, 'IdentityAccess\\Infrastructure'));
            assertSameValue(false, str_contains($source, 'IdentityAccess\\Application'));
            assertSameValue(false, str_contains($source, 'PDO'));
        }
    });

    $runner->add('Phase 4 PDO capability grant is exact idempotent and transaction-owned by caller', function (): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY); CREATE TABLE user_capabilities ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, capability_code TEXT COLLATE BINARY NOT NULL, '
            . 'UNIQUE(user_id, capability_code), FOREIGN KEY(user_id) REFERENCES users(id)); INSERT INTO users(id) VALUES(1)');
        $grant = phase4PdoGrant($pdo);
        $grant->grant(new UserId(1));
        $grant->grant(new UserId(1));
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM user_capabilities')->fetchColumn());
        assertSameValue('REPORTS_VIEW_ALL', $pdo->query('SELECT capability_code FROM user_capabilities')->fetchColumn());

        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO users(id) VALUES(2)');
        $grant->grant(new UserId(2));
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM user_capabilities WHERE user_id = 2')->fetchColumn());
    });

    $runner->add('Phase 4 PDO capability current read requires caller transaction', function (): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE user_capabilities (user_id INTEGER NOT NULL, capability_code TEXT NOT NULL); '
            . "INSERT INTO user_capabilities VALUES (1, 'REPORTS_VIEW_ALL')");
        $reflection = new ReflectionClass(PdoUserCapabilityLookup::class);
        $lookup = $reflection->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(PdoUserCapabilityLookup::class, 'connection');
        $property->setValue($lookup, $pdo);

        assertThrows(
            fn () => $lookup->hasCapabilityForUpdate(new UserId(1), CanAccessAllReports::CAPABILITY),
            \RuntimeException::class,
        );
        $pdo->beginTransaction();
        try {
            assertSameValue(true, $lookup->hasCapabilityForUpdate(
                new UserId(1),
                CanAccessAllReports::CAPABILITY,
            ));
        } finally {
            $pdo->rollBack();
        }
    });

    $runner->add('Phase 4 Delivery exposes only exact contextual routes and secret-free Views', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        assertSameValue(51, substr_count($routes, '$router->get('));
        assertSameValue(78, substr_count($routes, '$router->post('));
        assertSameValue(1, substr_count($routes, "'/institutional-reporting-access/manage'"));
        foreach (['provision', 'disable', 'reactivate'] as $action) {
            assertSameValue(1, substr_count($routes, "'/institutional-reporting-access/{$action}'"));
        }
        assertSameValue(true, str_contains($routes, 'AuthenticationMiddleware::class'));
        assertSameValue(true, str_contains($routes, 'PersonAdministrationMiddleware::class'));

        $view = (string) file_get_contents(
            dirname(__DIR__) . '/resources/views/institutional-reporting-access/manage.php'
        );
        foreach (['PasswordHash', 'REPORTS_VIEW_ALL', 'user_id', 'capability_code'] as $forbidden) {
            assertSameValue(false, str_contains($view, $forbidden), $forbidden);
        }
        assertSameValue(false, preg_match('/name="password"[^>]*value=/i', $view) === 1);
        assertSameValue(true, str_contains($view, 'Acceso institucional a reportes'));
        assertSameValue(true, str_contains($view, 'method="post"'));
    });

    $runner->add('Phase 4 real container resolves lifecycle graph and registers all routes', function (): void {
        $app = require dirname(__DIR__) . '/bootstrap/app.php';
        $manager = new ConnectionManager(
            new ConnectionFactory(),
            new DatabaseConfig([
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'container-smoke-not-used',
                'username' => 'container-smoke-not-used',
                'password' => '',
                'charset' => 'utf8mb4',
            ]),
        );
        $connectionProperty = new ReflectionProperty(ConnectionManager::class, 'connection');
        $connectionProperty->setValue($manager, new PDO('sqlite::memory:'));
        $app->container()->instance(ConnectionManager::class, $manager);

        foreach ([
            GetInstitutionalReportingAccessState::class,
            GrantInstitutionalReportingAccessToExistingUser::class,
            ProvisionInstitutionalReportingUser::class,
            DisableInstitutionalReportingUser::class,
            ReactivateInstitutionalReportingUser::class,
            InstitutionalReportingAccessController::class,
        ] as $service) {
            assertSameValue(true, $app->container()->make($service) instanceof $service, $service);
        }

        $router = $app->router();
        require dirname(__DIR__) . '/routes/web.php';
        assertSameValue(51, substr_count(
            (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'),
            '$router->get(',
        ));
    });
}

/** @return array<string, mixed> */
function phase4Fixture(int $personId, bool $withEmail = false): array
{
    $persons = new InMemoryPersonApplicationRepository(new DateTimeImmutable('2026-10-30'));
    $persons->seed(new Person(
        new PersonId($personId),
        new PersonalName('Persona', null, 'Institucional', null),
        null,
        new DateTimeImmutable('1990-01-01'),
        1,
        null,
        null,
        $withEmail ? new ContactInformation('person@example.test', null, null) : null,
        PersonStatus::Active,
        new DateTimeImmutable('2026-10-30'),
    ));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $students = new InMemoryStudentApplicationRepository();
    $users = new InMemoryRepresentativeUserRepository();
    $capabilities = new InMemoryInstitutionalReportingCapabilities();

    return [
        'persons' => $persons,
        'representatives' => $representatives,
        'students' => $students,
        'users' => $users,
        'capabilities' => $capabilities,
        'eligibility' => new InstitutionalReportingPersonEligibility(
            $persons,
            $representatives,
            $students,
        ),
        'state' => new GetInstitutionalReportingAccessState(
            $persons,
            $representatives,
            $students,
            $users,
            new IsDedicatedInstitutionalReportingUser($users, $capabilities),
        ),
    ];
}

function phase4User(
    int $id,
    int $personId,
    string $login,
    UserStatus $status = UserStatus::Active,
    int $failedAttempts = 0,
    ?DateTimeImmutable $lockedAt = null,
    ?DateTimeImmutable $lastAccess = null,
): User {
    return new User(
        new UserId($id),
        new UserPersonId($personId),
        new LoginIdentifier($login),
        new PasswordHash('preserved-hash'),
        $status,
        $failedAttempts,
        $lockedAt,
        $lastAccess,
    );
}

function phase4Student(int $id, int $personId): \App\Student\Domain\Student
{
    $today = new DateTimeImmutable('2026-10-30', new DateTimeZone('UTC'));

    return new \App\Student\Domain\Student(
        new \App\Student\Domain\ValueObject\StudentId($id),
        new \App\Student\Domain\ValueObject\PersonId($personId),
        new \App\Student\Domain\ValueObject\InstitutionalCode('P4-' . $personId),
        new \App\Student\Domain\ValueObject\AdmissionDate(
            new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')),
            $today,
        ),
        StudentStatus::Active,
    );
}

function phase4UserSnapshot(User $user): array
{
    return [
        $user->id()?->value(),
        $user->personId()->value(),
        $user->loginIdentifier()->value(),
        $user->passwordHash()->value(),
        $user->failedLoginAttempts(),
        $user->lockedAt()?->format(DATE_ATOM),
        $user->lastAccessAt()?->format(DATE_ATOM),
    ];
}

function phase4PdoGrant(PDO $pdo): PdoInstitutionalReportingCapabilityGrant
{
    $reflection = new ReflectionClass(PdoInstitutionalReportingCapabilityGrant::class);
    $grant = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoInstitutionalReportingCapabilityGrant::class, 'connection');
    $property->setValue($grant, $pdo);

    return $grant;
}

final class InMemoryInstitutionalReportingCapabilities implements LockingUserCapabilityLookup, InstitutionalReportingCapabilityGrant
{
    /** @var array<int, true> */
    private array $assigned = [];

    private bool $ordinarySnapshotStale = false;

    public int $lockingReads = 0;

    public function hasCapability(UserId $userId, string $capabilityCode): bool
    {
        return !$this->ordinarySnapshotStale
            && $capabilityCode === CanAccessAllReports::CAPABILITY
            && isset($this->assigned[$userId->value()]);
    }

    public function hasCapabilityForUpdate(UserId $userId, string $capabilityCode): bool
    {
        $this->lockingReads++;

        return $capabilityCode === CanAccessAllReports::CAPABILITY
            && isset($this->assigned[$userId->value()]);
    }

    public function grant(UserId $userId): void
    {
        $this->assigned[$userId->value()] = true;
    }

    public function makeOrdinarySnapshotStale(): void
    {
        $this->ordinarySnapshotStale = true;
    }
}
