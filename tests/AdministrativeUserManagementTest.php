<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserAccessType;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserSearchCriteria;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserLoginIdentifier;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserPassword;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserStatus;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeInstitutionalReportingUserLoginIdentifier;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeInstitutionalReportingUserPassword;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeRepresentativeUserStatus;
use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\AdministrativeUsers\GetAdministrativeUser;
use App\IdentityAccess\Application\AdministrativeUsers\SearchAdministrativeUsers;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\DisableInstitutionalReportingUser;
use App\IdentityAccess\Application\IsDedicatedInstitutionalReportingUser;
use App\IdentityAccess\Application\LockRepresentativeUserForAdministration;
use App\IdentityAccess\Application\ReactivateInstitutionalReportingUser;
use App\IdentityAccess\Application\Security\InstitutionalReportingPasswordPolicy;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Persistence\PdoAdministrativeUserQuery;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use Tests\Support\TestRunner;

function registerAdministrativeUserManagementTests(TestRunner $runner): void
{
    $runner->add('Admin User management search normalizes criteria and remains bounded', function (): void {
        $rows = [];
        for ($id = 1; $id <= 31; $id++) {
            $rows[] = administrativeUserRow($id, AdministrativeUserAccessType::Representative, $id + 100);
        }
        $query = new InMemoryAdministrativeUserQuery($rows);
        $result = (new SearchAdministrativeUsers($query))->handle(
            new AdministrativeUserSearchCriteria('name', '  Ana   Pérez  ')
        );

        assertSameValue(31, $query->lastLimit);
        assertSameValue('Ana Pérez', $query->lastCriteria?->value);
        assertSameValue(30, count($result->rows));
        assertSameValue(true, $result->hasMore);
        assertSameValue(false, array_key_exists('passwordHash', get_object_vars($result->rows[0])));

        $pdoQuery = (new \ReflectionClass(PdoAdministrativeUserQuery::class))->newInstanceWithoutConstructor();
        $predicate = new \ReflectionMethod(PdoAdministrativeUserQuery::class, 'predicate');
        [$sql, $parameters] = $predicate->invoke(
            $pdoQuery,
            new AdministrativeUserSearchCriteria('name', 'Ana Pérez'),
        );
        assertSameValue(true, str_contains($sql, ') AND ('));
        assertSameValue(9, count($parameters));
        assertSameValue('Ana%', $parameters[':name0_0']);
        assertSameValue('Pérez%', $parameters[':name1_3']);
    });

    $runner->add('Admin User query derives account types without persisting new roles', function (): void {
        $query = (new \ReflectionClass(PdoAdministrativeUserQuery::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PdoAdministrativeUserQuery::class, 'mapRows');
        $rows = $method->invoke($query, [
            administrativeUserDatabaseRow(1, 'admin', null, null, 1),
            administrativeUserDatabaseRow(2, 'representative', 12, null, 0),
            administrativeUserDatabaseRow(3, 'reporting', null, null, 1),
            administrativeUserDatabaseRow(4, 'incoherent', 13, null, 1),
            administrativeUserDatabaseRow(5, 'student-user', null, 14, 0),
            administrativeUserDatabaseRow(6, 'unsupported', null, null, 0),
            administrativeUserDatabaseRow(7, 'representative-student', 15, 16, 0),
            administrativeUserDatabaseRow(8, 'student-reporting', null, 17, 1),
            administrativeUserDatabaseRow(9, 'admin', 18, 19, 1),
        ]);

        assertSameValue([
            AdministrativeUserAccessType::Administrator,
            AdministrativeUserAccessType::Representative,
            AdministrativeUserAccessType::InstitutionalReporting,
            AdministrativeUserAccessType::Incoherent,
            AdministrativeUserAccessType::Incoherent,
            AdministrativeUserAccessType::Unsupported,
            AdministrativeUserAccessType::Representative,
            AdministrativeUserAccessType::Incoherent,
            AdministrativeUserAccessType::Administrator,
        ], array_column($rows, 'accessType'));

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Infrastructure/Persistence/PdoAdministrativeUserQuery.php'
        );
        assertSameValue(false, str_contains($source, 'password_hash'));
        assertSameValue(false, str_contains($source, 'SELECT *'));
    });

    $runner->add('Admin User PDO query executes bounded prepared discovery over existing relationships', function (): void {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
            . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
            . 'CREATE TABLE persons (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, middle_name TEXT NULL, '
            . 'first_surname TEXT NOT NULL, second_surname TEXT NULL, document_number TEXT NULL);'
            . 'CREATE TABLE users (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL, status_id INTEGER NOT NULL, '
            . 'login_identifier TEXT NOT NULL, normalized_login_identifier TEXT NOT NULL, last_access_at TEXT NULL);'
            . 'CREATE TABLE representatives (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL);'
            . 'CREATE TABLE students (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL);'
            . 'CREATE TABLE user_capabilities (user_id INTEGER NOT NULL, capability_code TEXT NOT NULL);'
            . "INSERT INTO status_types VALUES (1, 'USER_STATUS');"
            . "INSERT INTO statuses VALUES (1, 1, 'ACTIVE'), (2, 1, 'DISABLED');"
            . "INSERT INTO persons VALUES (101, 'Ana', NULL, 'Pérez', NULL, 'ID-101'), "
            . "(102, 'Bruno', NULL, 'Representante', NULL, 'ID-102'), "
            . "(103, 'Carla', NULL, 'Reportes', NULL, 'ID-103');"
            . "INSERT INTO users VALUES (1, 101, 1, 'admin', 'admin', NULL), "
            . "(2, 102, 1, 'rep-102', 'rep-102', '2026-11-08 10:00:00'), "
            . "(3, 103, 2, 'reportes-103', 'reportes-103', NULL);"
            . 'INSERT INTO representatives VALUES (22, 102);'
            . "INSERT INTO user_capabilities VALUES (1, 'REPORTS_VIEW_ALL'), (3, 'REPORTS_VIEW_ALL');"
        );
        $query = (new \ReflectionClass(PdoAdministrativeUserQuery::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PdoAdministrativeUserQuery::class, 'connection'))->setValue($query, $pdo);

        $name = $query->search(new AdministrativeUserSearchCriteria('name', 'Ana Pérez'), 31);
        assertSameValue([1], array_column($name, 'userId'));
        assertSameValue(AdministrativeUserAccessType::Administrator, $name[0]->accessType);
        $identification = $query->search(
            new AdministrativeUserSearchCriteria('identification_number', ' id-102 '),
            31,
        );
        assertSameValue([2], array_column($identification, 'userId'));
        assertSameValue(AdministrativeUserAccessType::Representative, $identification[0]->accessType);
        $login = $query->search(new AdministrativeUserSearchCriteria('login_identifier', ' REPORTES-103 '), 31);
        assertSameValue([3], array_column($login, 'userId'));
        assertSameValue(UserStatus::Disabled, $login[0]->status);
        assertSameValue(AdministrativeUserAccessType::InstitutionalReporting, $login[0]->accessType);
        assertSameValue(2, $query->findById(2)?->userId);
    });

    $runner->add('Admin User management reuses Representative lifecycle and preserves login synchronization rule', function (): void {
        $services = administrativeMutationServices(
            administrativeUserRow(701, AdministrativeUserAccessType::Representative, 801),
            true,
        );

        assertSameValue(true, $services['status']->handle(701, false));
        assertSameValue(UserStatus::Disabled, $services['users']->findById(new UserId(701))?->status());
        assertSameValue(true, $services['status']->handle(701, true));
        $services['password']->handle(701, 'nueva-clave');
        $stored = $services['users']->findById(new UserId(701));
        assertSameValue(true, (new NativePasswordHasher())->verify(
            'nueva-clave',
            $stored?->passwordHash()->value() ?? '',
        ));
        assertSameValue('user-701', $stored?->loginIdentifier()->value());
        assertThrows(
            fn () => $services['login']->handle(701, 'otro-identificador'),
            AdministrativeUserOperationNotAllowed::class,
        );
    });

    $runner->add('Admin User management preserves dedicated reporting eligibility and lifecycle', function (): void {
        $services = administrativeMutationServices(
            administrativeUserRow(702, AdministrativeUserAccessType::InstitutionalReporting),
            false,
            true,
        );

        assertSameValue(true, $services['status']->handle(702, false));
        assertSameValue(UserStatus::Disabled, $services['users']->findById(new UserId(702))?->status());
        assertSameValue(true, $services['status']->handle(702, true));
        $services['password']->handle(702, 'reporte-seguro');
        assertSameValue(true, $services['login']->handle(702, '  Reportes.Institucional  '));

        $stored = $services['users']->findById(new UserId(702));
        assertSameValue('reportes.institucional', $stored?->loginIdentifier()->value());
        assertSameValue(true, (new NativePasswordHasher())->verify(
            'reporte-seguro',
            $stored?->passwordHash()->value() ?? '',
        ));
        assertSameValue(true, $services['capabilities']->hasCapability(
            new UserId(702),
            'REPORTS_VIEW_ALL',
        ));
    });

    $runner->add('Admin User management protects Admin and unsupported accounts from mutation', function (): void {
        foreach ([AdministrativeUserAccessType::Administrator, AdministrativeUserAccessType::Unsupported] as $type) {
            $services = administrativeMutationServices(administrativeUserRow(703, $type));
            assertThrows(fn () => $services['status']->handle(703, false), AdministrativeUserOperationNotAllowed::class);
            assertThrows(fn () => $services['password']->handle(703, 'cinco'), AdministrativeUserOperationNotAllowed::class);
            assertThrows(fn () => $services['login']->handle(703, 'nuevo'), AdministrativeUserOperationNotAllowed::class);
        }
    });

    $runner->add('Admin User mutations reject stale Representative classification at execution time', function (): void {
        $row = administrativeUserRow(704, AdministrativeUserAccessType::Representative, 804);
        $withCapability = administrativeMutationServices($row, true);
        $withCapability['capabilities']->grant(new UserId(704));

        assertThrows(
            fn () => $withCapability['status']->handle(704, false),
            AdministrativeUserOperationNotAllowed::class,
        );
        assertThrows(
            fn () => $withCapability['password']->handle(704, 'nueva-clave'),
            AdministrativeUserOperationNotAllowed::class,
        );
        $stored = $withCapability['users']->findById(new UserId(704));
        assertSameValue(UserStatus::Active, $stored?->status());
        assertSameValue('preserved-hash', $stored?->passwordHash()->value());

        $withoutRepresentative = administrativeMutationServices($row);
        assertThrows(
            fn () => $withoutRepresentative['status']->handle(704, false),
            AdministrativeUserOperationNotAllowed::class,
        );
        assertThrows(
            fn () => $withoutRepresentative['password']->handle(704, 'nueva-clave'),
            AdministrativeUserOperationNotAllowed::class,
        );
    });

    $runner->add('Admin User Delivery exposes only exact Admin operations and no secret or capability editor', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        foreach (['/admin/users', '/admin/users/search', '/admin/users/manage', '/admin/users/status', '/admin/users/password', '/admin/users/login-identifier'] as $route) {
            assertSameValue(1, substr_count($routes, "'{$route}'"), $route);
        }
        foreach (['/admin/users/create', '/admin/users/delete', '/admin/users/capabilities'] as $excluded) {
            assertSameValue(false, str_contains($routes, $excluded), $excluded);
        }
        assertSameValue(7, substr_count($routes, '$administrativeUserMiddleware'));
        $middleware = substr(
            $routes,
            (int) strpos($routes, '$administrativeUserMiddleware = ['),
            180,
        );
        assertSameValue(true, str_contains($middleware, 'AuthenticationMiddleware::class'));
        assertSameValue(true, str_contains($middleware, 'PersonAdministrationMiddleware::class'));

        $views = (string) file_get_contents(dirname(__DIR__) . '/resources/views/admin-users/index.php')
            . (string) file_get_contents(dirname(__DIR__) . '/resources/views/admin-users/manage.php');
        foreach (['password_hash', 'PasswordHash', 'capability_code', 'REPORTS_VIEW_ALL'] as $forbidden) {
            assertSameValue(false, str_contains($views, $forbidden), $forbidden);
        }
        assertSameValue(false, preg_match('/type="password"[^>]*value=/i', $views) === 1);
    });
}

/** @return array<string, mixed> */
function administrativeMutationServices(
    AdministrativeUserRow $row,
    bool $representative = false,
    bool $reporting = false,
): array {
    $fixture = phase4Fixture($row->personId, true);
    $fixture['users']->seed(phase4User($row->userId, $row->personId, $row->loginIdentifier));
    if ($representative) {
        $fixture['representatives']->seed(representativeAggregate(
            $row->representativeId ?? 801,
            $row->personId,
        ));
    }
    if ($reporting) {
        $fixture['capabilities']->grant(new UserId($row->userId));
    }

    $query = new InMemoryAdministrativeUserQuery([$row]);
    $getUser = new GetAdministrativeUser($query);
    $profile = new IsDedicatedInstitutionalReportingUser($fixture['users'], $fixture['capabilities']);
    $events = new FakeSecurityEvents();
    $hasher = new NativePasswordHasher();
    $reportingPassword = new ChangeInstitutionalReportingUserPassword(
        new ImmediateTransactionManager(),
        $fixture['eligibility'],
        $fixture['users'],
        $profile,
        $hasher,
        new InstitutionalReportingPasswordPolicy(),
    );
    $reportingLogin = new ChangeInstitutionalReportingUserLoginIdentifier(
        new ImmediateTransactionManager(),
        $fixture['eligibility'],
        $fixture['users'],
        $profile,
    );

    return $fixture + [
        'status' => new ChangeAdministrativeUserStatus(
            $getUser,
            new ChangeRepresentativeUserStatus(
                new ImmediateTransactionManager(),
                $fixture['users'],
                new LockRepresentativeUserForAdministration(
                    $fixture['persons'],
                    $fixture['representatives'],
                    $fixture['users'],
                    $profile,
                ),
            ),
            new DisableInstitutionalReportingUser(
                new ImmediateTransactionManager(),
                $fixture['persons'],
                $fixture['users'],
                $profile,
                $events,
            ),
            new ReactivateInstitutionalReportingUser(
                new ImmediateTransactionManager(),
                $fixture['eligibility'],
                $fixture['users'],
                $profile,
                $events,
            ),
        ),
        'password' => new ChangeAdministrativeUserPassword(
            $getUser,
            new ChangeRepresentativeUserPassword(
                new ImmediateTransactionManager(),
                new LockRepresentativeUserForAdministration(
                    $fixture['persons'],
                    $fixture['representatives'],
                    $fixture['users'],
                    $profile,
                ),
                $fixture['users'],
                $hasher,
                new RepresentativePasswordPolicy(),
            ),
            $reportingPassword,
        ),
        'login' => new ChangeAdministrativeUserLoginIdentifier($getUser, $reportingLogin),
    ];
}

function administrativeUserRow(
    int $userId,
    AdministrativeUserAccessType $type,
    ?int $representativeId = null,
): AdministrativeUserRow {
    return new AdministrativeUserRow(
        $userId,
        $userId + 1000,
        'Persona de prueba',
        'ID-' . $userId,
        $type === AdministrativeUserAccessType::Administrator ? 'admin' : 'user-' . $userId,
        UserStatus::Active,
        null,
        $type,
        $representativeId,
    );
}

/** @return array<string, int|string|null> */
function administrativeUserDatabaseRow(
    int $id,
    string $login,
    ?int $representativeId,
    ?int $studentId,
    int $hasReports,
): array {
    return [
        'user_id' => $id,
        'person_id' => $id + 100,
        'login_identifier' => $login,
        'last_access_at' => null,
        'first_name' => 'Persona',
        'middle_name' => null,
        'first_surname' => 'Prueba',
        'second_surname' => null,
        'document_number' => 'ID-' . $id,
        'user_status' => 'ACTIVE',
        'status_type' => 'USER_STATUS',
        'representative_id' => $representativeId,
        'student_id' => $studentId,
        'has_reports_capability' => $hasReports,
    ];
}
