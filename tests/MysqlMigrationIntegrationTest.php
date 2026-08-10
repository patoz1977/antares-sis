<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\Orchestration\UpdatePersonWithRepresentativeUserSync;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\IdentityAccess\Infrastructure\Persistence\PdoTransactionManager;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentReference;
use App\Family\Infrastructure\Persistence\PdoFamilyRepository;
use App\Family\Infrastructure\Persistence\PdoFamilyFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoRelationshipTypeLookup;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\UpdatePerson;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;
use App\Person\Infrastructure\Persistence\PdoPersonFormOptionsProvider;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Infrastructure\Persistence\PdoRepresentativeRepository;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Application\CreateStudent;
use App\Student\Infrastructure\Persistence\PdoStudentRepository;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\MigrationRunner;
use Core\Database\PdoTransactionRunner;
use Database\Seeders\AdminSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/seeders/AdminSeeder.php';

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function diagnosticValue(mixed $value): string
{
    if (is_string($value)) {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '"(unrepresentable string)"' : $encoded;
    }

    if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
        return var_export($value, true);
    }

    return sprintf('[%s value]', get_debug_type($value));
}

/**
 * @param array<string, mixed>|false $row
 * @param array<string, mixed>|false $timeZone
 */
function mariaDbFamilyPersistenceDiagnostics(array|false $row, array|false $timeZone): string
{
    $expected = [
        'started_at' => '2026-08-01 15:11:12',
        'ended_at' => null,
        'is_primary' => 1,
        'status_type_code' => 'GENERAL_STATUS',
        'status_code' => 'ACTIVE',
    ];
    $lines = ['Family persistence did not store UTC seconds or resolve exact GENERAL_STATUS.'];

    foreach ($expected as $field => $expectedValue) {
        $actualValue = $row !== false && array_key_exists($field, $row) ? $row[$field] : null;
        $lines[] = sprintf(
            '%s: expected=%s; actual=%s; actual PHP type=%s',
            $field,
            diagnosticValue($expectedValue),
            diagnosticValue($actualValue),
            get_debug_type($actualValue),
        );
    }

    $sessionTimeZone = $timeZone !== false && array_key_exists('session_time_zone', $timeZone)
        ? $timeZone['session_time_zone']
        : null;
    $systemTimeZone = $timeZone !== false && array_key_exists('system_time_zone', $timeZone)
        ? $timeZone['system_time_zone']
        : null;
    $lines[] = 'MariaDB session timezone: ' . diagnosticValue($sessionTimeZone);
    $lines[] = sprintf(
        '@@session.time_zone: actual=%s; actual PHP type=%s',
        diagnosticValue($sessionTimeZone),
        get_debug_type($sessionTimeZone),
    );
    $lines[] = sprintf(
        '@@system_time_zone: actual=%s; actual PHP type=%s',
        diagnosticValue($systemTimeZone),
        get_debug_type($systemTimeZone),
    );

    return implode(PHP_EOL, $lines);
}

/** @return array{family: array<string, mixed>|false, representatives: array<int, array<string, mixed>>, students: array<int, array<string, mixed>>} */
function mariaDbFamilyPhysicalState(PDO $connection, int $familyId): array
{
    $family = $connection->prepare(
        'SELECT id, display_name, status_id, created_at, updated_at FROM families WHERE id = :id'
    );
    $family->execute([':id' => $familyId]);
    $representatives = $connection->prepare(
        'SELECT id, representative_id, relationship_type_id, is_primary, started_at, ended_at '
        . 'FROM family_representatives WHERE family_id = :familyId ORDER BY id'
    );
    $representatives->execute([':familyId' => $familyId]);
    $students = $connection->prepare(
        'SELECT id, student_id, started_at, ended_at '
        . 'FROM family_students WHERE family_id = :familyId ORDER BY id'
    );
    $students->execute([':familyId' => $familyId]);

    return [
        'family' => $family->fetch(PDO::FETCH_ASSOC),
        'representatives' => $representatives->fetchAll(PDO::FETCH_ASSOC),
        'students' => $students->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function isExpectedMariaDbLockException(PDOException $exception): bool
{
    $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);
    $message = strtolower($exception->errorInfo[2] ?? $exception->getMessage());

    return ($sqlState === 'HY000' && $driverCode === 1205 && str_contains($message, 'lock wait timeout'))
        || ($sqlState === '40001' && $driverCode === 1213 && str_contains($message, 'deadlock'));
}

function mariaDbPersonCollationDiagnostics(PDO $connection): string
{
    try {
        $session = $connection->query(
            'SELECT @@character_set_connection AS character_set_connection, '
            . '@@collation_connection AS collation_connection'
        )->fetch(PDO::FETCH_ASSOC);
        $columns = $connection->query(
            "SELECT column_name, collation_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'persons' "
            . "AND column_name IN ('document_number', 'identification_key') ORDER BY column_name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return sprintf(
            'character_set_connection=%s; collation_connection=%s; '
            . 'persons.document_number=%s; persons.identification_key=%s',
            $session['character_set_connection'] ?? '(unavailable)',
            $session['collation_connection'] ?? '(unavailable)',
            $columns['document_number'] ?? '(missing)',
            $columns['identification_key'] ?? '(missing)',
        );
    } catch (Throwable $exception) {
        return 'Collation diagnostics unavailable: ' . $exception->getMessage();
    }
}

function findPersonWithCollationDiagnostics(
    PDO $connection,
    PdoPersonRepository $repository,
    Identification $identification,
): ?Person {
    try {
        return $repository->findByIdentification($identification);
    } catch (PDOException $exception) {
        if (!str_contains(strtolower($exception->getMessage()), 'collation')) {
            throw $exception;
        }

        throw new RuntimeException(
            'Person identification lookup failed because of a MariaDB collation conflict. '
            . mariaDbPersonCollationDiagnostics($connection),
            previous: $exception,
        );
    }
}

/**
 * @param list<string> $databases
 * @return list<string>
 */
function dropDisposableDatabases(PDO $server, array $databases): array
{
    $failures = [];

    foreach (array_reverse($databases) as $database) {
        try {
            $server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        } catch (Throwable $exception) {
            $failures[] = sprintf('%s: %s', $database, $exception->getMessage());
        }
    }

    return $failures;
}

/** @return list<string> */
function expectedBaselineTables(): array
{
    $tables = [
        'academic_periods', 'authorized_pickup_assignments', 'cantons', 'document_requirements',
        'document_types', 'education_levels', 'emergency_contact_assignments',
        'enrollment_document_acceptances', 'enrollment_submission_snapshots', 'enrollments',
        'families', 'family_addresses', 'family_authorized_pickups', 'family_emergency_contacts',
        'family_representatives', 'family_students', 'grades', 'institutional_document_versions',
        'institutional_documents', 'marital_statuses', 'migrations', 'parishes', 'persons', 'provinces',
        'relationship_types', 'representative_address_assignments', 'representatives', 'sections',
        'sexes', 'snapshot_addresses', 'snapshot_authorized_pickups', 'snapshot_emergency_contacts',
        'statuses', 'status_types', 'student_address_assignments', 'students', 'users',
    ];
    sort($tables);

    return $tables;
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 */
function schemaInventoryDifferenceMessage(array $expected, array $actual): string
{
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);

    $missing = array_values(array_diff($expected, $actual));
    $unexpected = array_values(array_diff($actual, $expected));

    return sprintf(
        "Clean migration inventory differs from the baseline.\n"
        . "Expected table count: %d\n"
        . "Actual table count: %d\n"
        . "Missing tables: %s\n"
        . "Unexpected tables: %s\n"
        . "Expected inventory: %s\n"
        . "Actual inventory: %s",
        count($expected),
        count($actual),
        $missing === [] ? '(none)' : implode(', ', $missing),
        $unexpected === [] ? '(none)' : implode(', ', $unexpected),
        implode(', ', $expected),
        implode(', ', $actual),
    );
}

$requiredNonEmptyEnvironment = [
    'E0041_DB_HOST',
    'E0041_DB_PORT',
    'E0041_DB_USERNAME',
    'E0041_DB_PREFIX',
];

$environment = [];
foreach ($requiredNonEmptyEnvironment as $environmentName) {
    $environmentValue = getenv($environmentName);
    if ($environmentValue === false || trim($environmentValue) === '') {
        throw new RuntimeException(
            sprintf('%s must be explicitly defined and non-empty; .env fallback is intentionally forbidden.', $environmentName)
        );
    }

    $environment[$environmentName] = $environmentValue;
}

$passwordValue = getenv('E0041_DB_PASSWORD');
if ($passwordValue === false) {
    throw new RuntimeException(
        'E0041_DB_PASSWORD must be explicitly defined; an empty value is allowed and .env fallback is intentionally forbidden.'
    );
}
$environment['E0041_DB_PASSWORD'] = $passwordValue;

if (getenv('E0041_DB_ALLOW_DISPOSABLE') !== '1') {
    throw new RuntimeException(
        'E0041_DB_ALLOW_DISPOSABLE=1 is required to authorize disposable databases.'
    );
}

$host = $environment['E0041_DB_HOST'];
$port = (int) $environment['E0041_DB_PORT'];
$username = $environment['E0041_DB_USERNAME'];
$password = $environment['E0041_DB_PASSWORD'];
$databasePrefix = $environment['E0041_DB_PREFIX'];
$charset = 'utf8mb4';

assertIntegration(
    preg_match('/^[a-z][a-z0-9_]{2,30}$/', $databasePrefix) === 1,
    'E0041_DB_PREFIX must be a safe lowercase disposable prefix.'
);
foreach ([$host, $username, $password, $databasePrefix] as $environmentValue) {
    assertIntegration(
        !str_contains(strtolower($environmentValue), 'ueant'),
        'UEAnt is explicitly forbidden in every E004.1 integration-test environment value.'
    );
}

echo sprintf(
    "MariaDB disposable target: host=%s port=%d prefix=%s\n",
    $host,
    $port,
    $databasePrefix
);

$suffix = bin2hex(random_bytes(5));
$identityDatabase = $databasePrefix . '_identity_' . $suffix;
$cleanupProbePrefix = $databasePrefix . '_cleanup_' . $suffix;
$cleanupProbeDatabase = $cleanupProbePrefix . '_first';

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$createdDatabases = [];
try {
    $cleanupProbeCreated = [];
    try {
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $cleanupProbeDatabase
        ));
        $cleanupProbeCreated[] = $cleanupProbeDatabase;
        throw new RuntimeException('Intentional failure after the first disposable database creation.');
    } catch (RuntimeException $exception) {
        assertIntegration(
            $exception->getMessage() === 'Intentional failure after the first disposable database creation.',
            'Partial database creation failed for an unexpected reason.'
        );
    } finally {
        $cleanupFailures = dropDisposableDatabases($server, $cleanupProbeCreated);
        assertIntegration(
            $cleanupFailures === [],
            'Partial-creation cleanup failed: ' . implode('; ', $cleanupFailures)
        );
    }

    $cleanupProbeStatement = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE LOCATE(:prefix, schema_name) = 1'
    );
    $cleanupProbeStatement->execute([':prefix' => $cleanupProbePrefix]);
    assertIntegration(
        (int) $cleanupProbeStatement->fetchColumn() === 0,
        'Partial-creation cleanup left a disposable database behind.'
    );

    assertIntegration(
        preg_match('/^[a-z][a-z0-9_]{2,30}_identity_[a-f0-9]{10}$/', $identityDatabase) === 1,
        'Unsafe disposable database name.'
    );
    $server->exec(sprintf(
        'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $identityDatabase
    ));
    $createdDatabases[] = $identityDatabase;

    $identity = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $identityDatabase, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $identity->exec("SET time_zone = '+00:00'");
    assertIntegration(
        $identity->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00',
        'MariaDB harness inspection session did not establish the UTC SQL convention.'
    );
    $databaseConfig = new DatabaseConfig([
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'database' => $identityDatabase,
        'username' => $username,
        'password' => $password,
        'charset' => $charset,
    ]);
    $managerA = new ConnectionManager(new ConnectionFactory(), $databaseConfig);
    (new MigrationRunner($managerA))->run();
    $connectionA = $managerA->connection();

    $sessionCollation = $connectionA->query(
        'SELECT @@character_set_connection AS character_set_connection, '
        . '@@collation_connection AS collation_connection'
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $sessionCollation !== false
        && $sessionCollation['character_set_connection'] === 'utf8mb4'
        && $sessionCollation['collation_connection'] === 'utf8mb4_unicode_ci',
        'ConnectionFactory did not establish the approved MariaDB charset and collation. '
        . mariaDbPersonCollationDiagnostics($connectionA)
    );

    $actualTables = $identity->query(
        'SELECT table_name FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($actualTables, SORT_STRING);
    $expectedTables = expectedBaselineTables();
    assertIntegration(
        $actualTables === $expectedTables,
        schemaInventoryDifferenceMessage($expectedTables, $actualTables)
    );
    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 9, 'Not all baseline migrations were recorded.');
    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM status_types')->fetchColumn() === 3, 'Status type baseline is incomplete.');
    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM statuses')->fetchColumn() === 8, 'Status baseline is incomplete.');

    $identity->exec(
        "INSERT INTO document_types (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO sexes (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO sexes (id, code, name, is_active) "
        . "VALUES (2, 'INACTIVE_TEST', 'Inactive disposable test value', FALSE)"
    );
    $identity->exec(
        "INSERT INTO marital_statuses (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO education_levels (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $generalStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $inactiveGeneralStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    $disabledUserStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'DISABLED'"
    )->fetchColumn();

    $personFormOptions = (new PdoPersonFormOptionsProvider($managerA))->get();
    assertIntegration(
        count($personFormOptions->documentTypes) === 1
        && count($personFormOptions->sexes) === 1
        && count($personFormOptions->maritalStatuses) === 1
        && count($personFormOptions->educationLevels) === 1,
        'Person form options did not load only active reference Catalog rows.'
    );
    assertIntegration(
        $personFormOptions->isReadyForSave()
        && array_map(
            static fn ($option): string => $option->code,
            $personFormOptions->statuses,
        ) === ['ACTIVE', 'INACTIVE'],
        'Person form options did not load the active GENERAL_STATUS values in order.'
    );
    $personInsert = $identity->prepare(
        'INSERT INTO persons (id, first_name, first_surname, birth_date, sex_id, status_id) '
        . 'VALUES (1, :firstName, :firstSurname, :birthDate, 1, :statusId)'
    );
    $personInsert->execute([
        ':firstName' => 'Disposable',
        ':firstSurname' => 'Administrator',
        ':birthDate' => '2000-01-01',
        ':statusId' => $generalStatusId,
    ]);
    $hash = password_hash('DisposableAdminPassword', PASSWORD_DEFAULT);
    assertIntegration(is_string($hash), 'Unable to create disposable password hash.');
    $insert = $identity->prepare(
        'INSERT INTO users '
        . '(person_id, login_identifier, normalized_login_identifier, password_hash, status_id, failed_login_attempts) '
        . 'VALUES (1, :loginIdentifier, :normalizedLoginIdentifier, :passwordHash, :statusId, 4)'
    );
    $insert->execute([
        ':loginIdentifier' => 'admin',
        ':normalizedLoginIdentifier' => 'admin',
        ':passwordHash' => $hash,
        ':statusId' => $disabledUserStatusId,
    ]);

    (new AdminSeeder())->run($identity);
    $preserved = $identity->query(
        "SELECT password_hash, status_id FROM users WHERE normalized_login_identifier = 'admin'"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $preserved !== false && $preserved['password_hash'] === $hash,
        'AdminSeeder replaced an existing password hash.'
    );
    assertIntegration(
        $preserved !== false && (int) $preserved['status_id'] === $disabledUserStatusId,
        'AdminSeeder changed an existing User status.'
    );

    $personRepository = new PdoPersonRepository($managerA);
    $personToday = new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC'));
    $person = new Person(
        null,
        new PersonalName('Disposable', 'Maria', 'Persistence', 'Probe'),
        new Identification(1, 'Person-100'),
        new DateTimeImmutable('2000-02-03', new DateTimeZone('UTC')),
        1,
        1,
        1,
        new ContactInformation('person@example.test', 'mobile extension', 'landline extension'),
        PersonStatus::Active,
        $personToday,
    );
    $persistedPerson = $personRepository->save($person);
    $generatedPersonId = $persistedPerson->id();
    assertIntegration($person->id() === null, 'Person insert replaced the identity of the new aggregate instance.');
    assertIntegration(
        $generatedPersonId !== null && $generatedPersonId->value() > 0,
        'MariaDB did not generate a positive Person identity.'
    );
    $secondPerson = new Person(
        null,
        new PersonalName('Second', null, 'Persistence', null),
        null,
        new DateTimeImmutable('2001-01-01', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $personToday,
    );
    $secondPersistedPerson = $personRepository->save($secondPerson);
    $secondGeneratedPersonId = $secondPersistedPerson->id();
    assertIntegration(
        $secondPerson->id() === null,
        'Second Person insert received or replaced a manual identity.'
    );
    assertIntegration(
        $secondGeneratedPersonId !== null && $secondGeneratedPersonId->value() > 0,
        'MariaDB did not generate a positive identity for the second Person.'
    );
    assertIntegration(
        !$generatedPersonId->equals($secondGeneratedPersonId),
        'MariaDB generated the same identity for two Persons.'
    );
    assertIntegration(
        $personRepository->findById($secondGeneratedPersonId)?->personalName()->firstName() === 'Second',
        'Second Person could not be reconstructed through its generated identity.'
    );
    $createdAtStatement = $identity->prepare('SELECT created_at FROM persons WHERE id = :id');
    $createdAtStatement->execute([':id' => $generatedPersonId->value()]);
    $createdAt = $createdAtStatement->fetchColumn();
    assertIntegration(
        $personRepository->findById($generatedPersonId)?->personalName()->middleName() === 'Maria',
        'Person repository did not reconstruct the inserted aggregate by ID.'
    );
    assertIntegration(
        findPersonWithCollationDiagnostics(
            $connectionA,
            $personRepository,
            new Identification(1, '  person-100  '),
        )?->id()?->value()
            === $generatedPersonId->value(),
        'Person repository did not use the normalized identification_key lookup.'
    );

    $duplicateIdentificationRejected = false;
    try {
        $personRepository->save(new Person(
            null,
            new PersonalName('Duplicate', null, 'Identification', null),
            new Identification(1, 'PERSON-100'),
            new DateTimeImmutable('2001-01-01', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            PersonStatus::Active,
            $personToday,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateIdentificationRejected = true;
    }
    assertIntegration(
        $duplicateIdentificationRejected,
        'MariaDB did not enforce normalized Person identification uniqueness.'
    );

    $persistedPerson->updateIdentity(
        new PersonalName('Updated', null, 'Persistence', null),
        null,
        new DateTimeImmutable('2001-04-05', new DateTimeZone('UTC')),
        1,
        null,
        null,
        $personToday,
    );
    $persistedPerson->updateContactInformation(null);
    $persistedPerson->deactivate();
    $updatedPersistedPerson = $personRepository->save($persistedPerson);
    $updatedPersonStatement = $identity->prepare(
        'SELECT p.document_type_id, p.document_number, p.identification_key, '
        . 'p.email, p.mobile_phone, p.landline_phone, p.created_at, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM persons p '
        . 'INNER JOIN statuses s ON s.id = p.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE p.id = :id'
    );
    $updatedPersonStatement->execute([':id' => $generatedPersonId->value()]);
    $updatedPerson = $updatedPersonStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $updatedPerson !== false
        && $updatedPerson['document_type_id'] === null
        && $updatedPerson['document_number'] === null
        && $updatedPerson['identification_key'] === null
        && $updatedPerson['email'] === null
        && $updatedPerson['mobile_phone'] === null
        && $updatedPerson['landline_phone'] === null,
        'Person update did not persist removed identification and contact fields as null.'
    );
    assertIntegration(
        $updatedPerson !== false
        && $updatedPerson['status_type_code'] === 'GENERAL_STATUS'
        && $updatedPerson['status_code'] === 'INACTIVE'
        && (int) $inactiveGeneralStatusId > 0,
        'Person update did not resolve INACTIVE through GENERAL_STATUS.'
    );
    assertIntegration(
        $updatedPerson !== false && $updatedPerson['created_at'] === $createdAt,
        'Person update modified created_at.'
    );
    assertIntegration(
        $updatedPersistedPerson->id()?->value() === $generatedPersonId->value()
        && $updatedPersistedPerson->status() === PersonStatus::Inactive,
        'Person repository did not reconstruct the updated INACTIVE aggregate.'
    );

    $representativeRepository = new PdoRepresentativeRepository($managerA);
    $newRepresentative = new Representative(
        null,
        new RepresentativePersonId($generatedPersonId->value()),
        new EmploymentInformation(
            'Disposable occupation',
            'Disposable company',
            'Disposable position',
            'disposable work phone',
            'representative@example.test',
        ),
        RepresentativeStatus::Active,
    );
    $persistedRepresentative = $representativeRepository->save($newRepresentative);
    $generatedRepresentativeId = $persistedRepresentative->id();
    assertIntegration(
        $newRepresentative->id() === null,
        'Representative insert replaced the identity of the new aggregate instance.'
    );
    assertIntegration(
        $generatedRepresentativeId !== null && $generatedRepresentativeId->value() > 0,
        'MariaDB did not generate a positive Representative identity.'
    );
    assertIntegration(
        $representativeRepository->findById($generatedRepresentativeId)?->employmentInformation()?->companyName()
            === 'Disposable company',
        'Representative repository did not reconstruct complete EmploymentInformation by ID.'
    );
    assertIntegration(
        $representativeRepository->findByPersonId(
            new RepresentativePersonId($generatedPersonId->value())
        )?->id()?->value() === $generatedRepresentativeId->value(),
        'Representative repository did not find the aggregate by PersonId.'
    );

    $duplicateRepresentativeRejected = false;
    try {
        $representativeRepository->save(new Representative(
            null,
            new RepresentativePersonId($generatedPersonId->value()),
            null,
            RepresentativeStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateRepresentativeRejected = true;
    }
    assertIntegration(
        $duplicateRepresentativeRejected,
        'MariaDB did not enforce Representative uniqueness by Person.'
    );

    $persistedRepresentative->replaceEmploymentInformation(
        new EmploymentInformation('Updated occupation', null, null, 'updated phone', null)
    );
    $persistedRepresentative->deactivate();
    $updatedRepresentative = $representativeRepository->save($persistedRepresentative);
    assertIntegration(
        $updatedRepresentative->status() === RepresentativeStatus::Inactive
        && $updatedRepresentative->employmentInformation()?->occupation() === 'Updated occupation'
        && $updatedRepresentative->employmentInformation()?->companyName() === null,
        'Representative update did not persist partial EmploymentInformation and INACTIVE status.'
    );

    $updatedRepresentative->replaceEmploymentInformation(null);
    $updatedRepresentative->activate();
    $clearedRepresentative = $representativeRepository->save($updatedRepresentative);
    $representativeStatusStatement = $identity->prepare(
        'SELECT r.occupation, r.company, r.position, r.work_phone, r.work_email, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM representatives r '
        . 'INNER JOIN statuses s ON s.id = r.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE r.id = :id'
    );
    $representativeStatusStatement->execute([':id' => $generatedRepresentativeId->value()]);
    $representativeRow = $representativeStatusStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeRow !== false
        && $representativeRow['occupation'] === null
        && $representativeRow['company'] === null
        && $representativeRow['position'] === null
        && $representativeRow['work_phone'] === null
        && $representativeRow['work_email'] === null
        && $representativeRow['status_type_code'] === 'GENERAL_STATUS'
        && $representativeRow['status_code'] === 'ACTIVE'
        && $clearedRepresentative->employmentInformation() === null,
        'Representative EmploymentInformation removal or exact GENERAL_STATUS mapping failed.'
    );

    $studentRepository = new PdoStudentRepository($managerA);
    $newStudent = new Student(
        null,
        new StudentPersonId($generatedPersonId->value()),
        new InstitutionalCode('Disposable-Student-100'),
        new AdmissionDate(
            new DateTimeImmutable('2020-09-01', new DateTimeZone('UTC')),
            $personToday,
        ),
        StudentStatus::Active,
    );
    $persistedStudent = $studentRepository->save($newStudent);
    $generatedStudentId = $persistedStudent->id();
    assertIntegration(
        $newStudent->id() === null,
        'Student insert replaced the identity of the new aggregate instance.'
    );
    assertIntegration(
        $generatedStudentId !== null && $generatedStudentId->value() > 0,
        'MariaDB did not generate a positive Student identity.'
    );
    assertIntegration(
        $studentRepository->findById($generatedStudentId)?->admissionDate()->value()->format('Y-m-d')
            === '2020-09-01',
        'Student repository did not reconstruct AdmissionDate by ID.'
    );
    assertIntegration(
        $studentRepository->findByPersonId(
            new StudentPersonId($generatedPersonId->value())
        )?->id()?->value() === $generatedStudentId->value(),
        'Student repository did not find the aggregate by PersonId.'
    );
    assertIntegration(
        $studentRepository->findByInstitutionalCode(
            new InstitutionalCode('disposable-student-100')
        )?->id()?->value() === $generatedStudentId->value(),
        'Student institutional-code lookup did not follow the official table collation.'
    );

    $duplicateStudentPersonRejected = false;
    try {
        $studentRepository->save(new Student(
            null,
            new StudentPersonId($generatedPersonId->value()),
            new InstitutionalCode('OTHER-STUDENT-CODE'),
            new AdmissionDate(
                new DateTimeImmutable('2021-01-01', new DateTimeZone('UTC')),
                $personToday,
            ),
            StudentStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateStudentPersonRejected = true;
    }
    assertIntegration(
        $duplicateStudentPersonRejected,
        'MariaDB did not enforce Student uniqueness by Person.'
    );

    $duplicateStudentCodeRejected = false;
    try {
        $studentRepository->save(new Student(
            null,
            new StudentPersonId($secondGeneratedPersonId->value()),
            new InstitutionalCode('DISPOSABLE-STUDENT-100'),
            new AdmissionDate(
                new DateTimeImmutable('2021-01-02', new DateTimeZone('UTC')),
                $personToday,
            ),
            StudentStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateStudentCodeRejected = true;
    }
    assertIntegration(
        $duplicateStudentCodeRejected,
        'MariaDB did not enforce InstitutionalCode uniqueness under the official collation.'
    );

    $persistedStudent->updateAcademicInformation(
        new InstitutionalCode('Updated-Student-100'),
        new AdmissionDate(
            new DateTimeImmutable('2022-03-04', new DateTimeZone('UTC')),
            $personToday,
        ),
    );
    $persistedStudent->deactivate();
    $updatedStudent = $studentRepository->save($persistedStudent);
    $studentStatusStatement = $identity->prepare(
        'SELECT s.person_id, s.institutional_code, s.admission_date, '
        . 'status_row.code AS status_code, st.code AS status_type_code '
        . 'FROM students s '
        . 'INNER JOIN statuses status_row ON status_row.id = s.status_id '
        . 'INNER JOIN status_types st ON st.id = status_row.status_type_id '
        . 'WHERE s.id = :id'
    );
    $studentStatusStatement->execute([':id' => $generatedStudentId->value()]);
    $studentRow = $studentStatusStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $studentRow !== false
        && (int) $studentRow['person_id'] === $generatedPersonId->value()
        && $studentRow['institutional_code'] === 'Updated-Student-100'
        && $studentRow['admission_date'] === '2022-03-04'
        && $studentRow['status_type_code'] === 'GENERAL_STATUS'
        && $studentRow['status_code'] === 'INACTIVE'
        && $updatedStudent->status() === StudentStatus::Inactive,
        'Student administrative update or exact GENERAL_STATUS mapping failed.'
    );

    $relationshipTypeInsert = $identity->prepare(
        'INSERT INTO relationship_types (code, name, is_active) '
        . 'VALUES (:code, :name, TRUE)'
    );
    $relationshipTypeInsert->execute([
        ':code' => 'DISPOSABLE_TEST_RELATIONSHIP',
        ':name' => 'Disposable test relationship',
    ]);
    $generatedRelationshipTypeId = (int) $identity->lastInsertId();
    assertIntegration(
        $generatedRelationshipTypeId > 0,
        'MariaDB did not generate the technical RelationshipType identity.'
    );
    $relationshipTypeInsert = $identity->prepare(
        'INSERT INTO relationship_types (code, name, is_active) '
        . 'VALUES (:code, :name, FALSE)'
    );
    $relationshipTypeInsert->execute([
        ':code' => 'DISPOSABLE_INACTIVE_RELATIONSHIP',
        ':name' => 'Inactive disposable relationship',
    ]);
    $inactiveRelationshipTypeId = (int) $identity->lastInsertId();
    assertIntegration(
        $inactiveRelationshipTypeId > 0,
        'MariaDB did not generate the inactive RelationshipType identity.'
    );

    $secondRepresentative = $representativeRepository->save(new Representative(
        null,
        new RepresentativePersonId($secondGeneratedPersonId->value()),
        null,
        RepresentativeStatus::Active,
    ));
    $secondRepresentativeId = $secondRepresentative->id();
    assertIntegration(
        $secondRepresentativeId !== null && $secondRepresentativeId->value() > 0,
        'MariaDB did not generate the additional Representative identity.'
    );

    $secondStudent = $studentRepository->save(new Student(
        null,
        new StudentPersonId($secondGeneratedPersonId->value()),
        new InstitutionalCode('DISPOSABLE-STUDENT-200'),
        new AdmissionDate(
            new DateTimeImmutable('2021-01-02', new DateTimeZone('UTC')),
            $personToday,
        ),
        StudentStatus::Active,
    ));
    $secondStudentId = $secondStudent->id();
    assertIntegration(
        $secondStudentId !== null && $secondStudentId->value() > 0,
        'MariaDB did not generate the additional Student identity.'
    );

    $familyRepository = new PdoFamilyRepository($managerA);
    $familyStartedAt = new DateTimeImmutable('2026-08-01 10:11:12-05:00');
    $newFamily = Family::create(
        new DisplayName('Disposable Family One'),
        FamilyStatus::Active,
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        $familyStartedAt,
    );
    $newFamily->addRepresentative(
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-02 09:00:00', new DateTimeZone('UTC')),
    );
    $newFamily->addStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-03 09:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily = $familyRepository->save($newFamily);
    $generatedFamilyId = $persistedFamily->id();
    $initialFamilyRepresentative = $persistedFamily->primaryRepresentative();
    assertIntegration(
        $newFamily->id() === null
        && $generatedFamilyId !== null
        && $generatedFamilyId->value() > 0
        && $initialFamilyRepresentative->id() !== null
        && $initialFamilyRepresentative->id()->value() > 0
        && $initialFamilyRepresentative->isActive()
        && $initialFamilyRepresentative->isPrimary(),
        'MariaDB did not atomically generate Family and its active primary membership identities.'
    );
    assertIntegration(
        count($persistedFamily->representatives()) === 2
        && count($persistedFamily->students()) === 1
        && $persistedFamily->students()[0]->id() !== null
        && $persistedFamily->students()[0]->id()->value() > 0,
        'Family repository did not reconstruct every generated membership identity.'
    );

    $familyTimestampStatement = $identity->prepare(
        'SELECT fr.started_at, fr.ended_at, fr.is_primary, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM family_representatives fr '
        . 'INNER JOIN families f ON f.id = fr.family_id '
        . 'INNER JOIN statuses s ON s.id = f.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE fr.id = :id'
    );
    $familyTimestampStatement->execute([':id' => $initialFamilyRepresentative->id()->value()]);
    $familyTimestampRow = $familyTimestampStatement->fetch(PDO::FETCH_ASSOC);
    $familyTimeZoneRow = $identity->query(
        'SELECT @@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone'
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $familyTimestampRow !== false
        && $familyTimestampRow['started_at'] === '2026-08-01 15:11:12'
        && $familyTimestampRow['ended_at'] === null
        && (int) $familyTimestampRow['is_primary'] === 1
        && $familyTimestampRow['status_type_code'] === 'GENERAL_STATUS'
        && $familyTimestampRow['status_code'] === 'ACTIVE',
        mariaDbFamilyPersistenceDiagnostics($familyTimestampRow, $familyTimeZoneRow)
    );

    $secondFamily = $familyRepository->save(Family::create(
        new DisplayName('Disposable Family Two'),
        FamilyStatus::Inactive,
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-04 09:00:00', new DateTimeZone('UTC')),
    ));
    $generatedSecondFamilyId = $secondFamily->id();
    assertIntegration(
        $generatedSecondFamilyId !== null
        && $generatedSecondFamilyId->value() > 0
        && !$generatedFamilyId->equals($generatedSecondFamilyId),
        'MariaDB Family AUTO_INCREMENT identities must be positive and distinct.'
    );
    $representativeFamilies = $familyRepository->findActiveByRepresentativeId(
        new FamilyRepresentativeReference($generatedRepresentativeId->value())
    );
    assertIntegration(
        array_map(
            static fn (Family $family): ?int => $family->id()?->value(),
            $representativeFamilies,
        ) === [$generatedFamilyId->value(), $generatedSecondFamilyId->value()],
        'Representative active-Family lookup did not return all complete Families deterministically.'
    );
    assertIntegration(
        $familyRepository->findActiveByStudentId(
            new FamilyStudentReference($generatedStudentId->value())
        )?->id()?->value() === $generatedFamilyId->value(),
        'Student active-Family lookup did not reconstruct the complete Family.'
    );

    $duplicateActiveRepresentativeRejected = false;
    try {
        $duplicateActiveRepresentative = $identity->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES (:familyId, :representativeId, :relationshipTypeId, FALSE, :startedAt, NULL)'
        );
        $duplicateActiveRepresentative->execute([
            ':familyId' => $generatedFamilyId->value(),
            ':representativeId' => $generatedRepresentativeId->value(),
            ':relationshipTypeId' => $generatedRelationshipTypeId,
            ':startedAt' => '2026-08-05 09:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateActiveRepresentativeRejected = true;
    }
    assertIntegration(
        $duplicateActiveRepresentativeRejected,
        'MariaDB did not enforce unique active Family and Representative membership.'
    );

    $persistedFamily->endRepresentativeMembership(
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new DateTimeImmutable('2026-08-06 09:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily->endStudentMembership(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-06 10:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily->updateDisplayName(new DisplayName('Disposable Family Updated'));
    $persistedFamily->deactivate();
    $updatedFamily = $familyRepository->save($persistedFamily);
    assertIntegration(
        $updatedFamily->displayName()->value() === 'Disposable Family Updated'
        && $updatedFamily->status() === FamilyStatus::Inactive
        && count($updatedFamily->representatives()) === 2
        && count($updatedFamily->activeRepresentatives()) === 1
        && count($updatedFamily->students()) === 1
        && count($updatedFamily->activeStudents()) === 0,
        'Family update did not preserve history or persist the approved mutable state.'
    );

    $principalUniquenessRejected = false;
    try {
        $secondPrimary = $identity->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES (:familyId, :representativeId, :relationshipTypeId, TRUE, :startedAt, NULL)'
        );
        $secondPrimary->execute([
            ':familyId' => $generatedFamilyId->value(),
            ':representativeId' => $secondRepresentativeId->value(),
            ':relationshipTypeId' => $generatedRelationshipTypeId,
            ':startedAt' => '2026-08-06 11:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $principalUniquenessRejected = true;
    }
    assertIntegration(
        $principalUniquenessRejected,
        'MariaDB did not enforce one active primary Representative per Family.'
    );

    $immutableMembershipStatement = $identity->prepare(
        'SELECT representative_id, relationship_type_id, is_primary, started_at, ended_at '
        . 'FROM family_representatives WHERE family_id = :familyId AND representative_id = :representativeId'
    );
    $immutableMembershipStatement->execute([
        ':familyId' => $generatedFamilyId->value(),
        ':representativeId' => $secondRepresentativeId->value(),
    ]);
    $immutableMembershipRow = $immutableMembershipStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $immutableMembershipRow !== false
        && (int) $immutableMembershipRow['representative_id'] === $secondRepresentativeId->value()
        && (int) $immutableMembershipRow['relationship_type_id'] === $generatedRelationshipTypeId
        && (int) $immutableMembershipRow['is_primary'] === 0
        && $immutableMembershipRow['started_at'] === '2026-08-02 09:00:00'
        && $immutableMembershipRow['ended_at'] === '2026-08-06 09:00:00',
        'FamilyRepresentative update changed immutable persisted fields.'
    );

    $secondFamily->addStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-07 09:00:00', new DateTimeZone('UTC')),
    );
    $secondFamily = $familyRepository->save($secondFamily);
    assertIntegration(
        $familyRepository->findActiveByStudentId(
            new FamilyStudentReference($generatedStudentId->value())
        )?->id()?->value() === $generatedSecondFamilyId->value(),
        'MariaDB did not permit a later Student membership after the previous one ended.'
    );

    $duplicateActiveStudentRejected = false;
    try {
        $persistedAgain = $familyRepository->findById($generatedFamilyId);
        assertIntegration($persistedAgain !== null, 'Family disappeared before Student uniqueness probe.');
        $persistedAgain->addStudent(
            new FamilyStudentReference($generatedStudentId->value()),
            new DateTimeImmutable('2026-08-08 09:00:00', new DateTimeZone('UTC')),
        );
        $familyRepository->save($persistedAgain);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateActiveStudentRejected = true;
    }
    assertIntegration(
        $duplicateActiveStudentRejected,
        'MariaDB did not enforce one active Family per Student.'
    );

    $invalidRelationshipRejected = false;
    $familyCountBeforeRollback = (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn();
    try {
        $familyRepository->save(Family::create(
            new DisplayName('Rollback Probe Family'),
            FamilyStatus::Active,
            new FamilyRepresentativeReference($secondRepresentativeId->value()),
            new RelationshipTypeId(999999999),
            new DateTimeImmutable('2026-08-09 09:00:00', new DateTimeZone('UTC')),
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $invalidRelationshipRejected = true;
    }
    assertIntegration(
        $invalidRelationshipRejected
        && (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn()
            === $familyCountBeforeRollback,
        'Family creation failure did not roll back its root row atomically.'
    );
    assertIntegration(
        (int) $identity->query(
            'SELECT COUNT(*) FROM families f '
            . 'LEFT JOIN family_representatives fr ON fr.family_id = f.id '
            . 'WHERE fr.id IS NULL'
        )->fetchColumn() === 0,
        'Family persistence left an orphan Family without a Representative membership.'
    );

    $relationshipTypes = new PdoRelationshipTypeLookup($managerA);
    assertIntegration(
        $relationshipTypes->exists($generatedRelationshipTypeId)
        && !$relationshipTypes->exists($inactiveRelationshipTypeId)
        && !$relationshipTypes->exists(0)
        && !$relationshipTypes->exists(999999999),
        'Productive RelationshipTypeLookup did not enforce positive active catalog identity.'
    );
    $familyFormOptions = (new PdoFamilyFormOptionsProvider($managerA))->get();
    assertIntegration(
        $familyFormOptions->isReadyForSave()
        && array_map(
            static fn ($option): string => $option->code,
            $familyFormOptions->relationshipTypes,
        ) === ['DISPOSABLE_TEST_RELATIONSHIP']
        && $familyFormOptions->statuses === [FamilyStatus::Active, FamilyStatus::Inactive],
        'Productive Family form options did not expose only active relationships and exact statuses.'
    );
    $transactions = new PdoTransactionRunner($managerA);
    $createPerson = new CreatePerson($personRepository);
    $createRepresentative = new CreateRepresentative(
        $personRepository,
        $representativeRepository,
    );
    $createStudent = new CreateStudent($personRepository, $studentRepository);
    $createFamily = new CreateFamily(
        $familyRepository,
        $representativeRepository,
        $relationshipTypes,
    );
    $compositeToday = new DateTimeImmutable('2026-08-04', new DateTimeZone('UTC'));
    $representativeFlow = new CreateRepresentativeFamily(
        $transactions,
        $createPerson,
        $createRepresentative,
        $createFamily,
    );
    $representativeFlowOutput = $representativeFlow->handle(
        new CreateRepresentativeFamilyInput(
            firstName: 'Composite',
            middleName: 'MariaDB',
            firstSurname: 'Representative',
            secondSurname: 'Success',
            documentTypeId: 1,
            documentNumber: 'COMPOSITE-REP-SUCCESS',
            birthDate: new DateTimeImmutable('1985-04-05', new DateTimeZone('UTC')),
            sexId: 1,
            maritalStatusId: 1,
            educationLevelId: 1,
            email: 'composite-representative@example.test',
            mobilePhone: 'composite mobile',
            landlinePhone: null,
            personStatus: PersonStatus::Active,
            occupation: 'Tester',
            companyName: null,
            position: null,
            workPhone: null,
            workEmail: 'composite-work@example.test',
            representativeStatus: RepresentativeStatus::Active,
            displayName: 'MariaDB Composite Representative Family',
            familyStatus: FamilyStatus::Active,
            relationshipTypeId: $generatedRelationshipTypeId,
            startedAt: new DateTimeImmutable('2026-08-10 10:11:12-05:00'),
        ),
        $compositeToday,
    );
    $representativeFlowRow = $identity->prepare(
        'SELECT p.id AS person_id, r.id AS representative_id, f.id AS family_id, '
        . 'fr.id AS membership_id, fr.representative_id AS membership_representative_id, '
        . 'fr.relationship_type_id, fr.is_primary, fr.ended_at '
        . 'FROM persons p INNER JOIN representatives r ON r.person_id = p.id '
        . 'INNER JOIN family_representatives fr ON fr.representative_id = r.id '
        . 'INNER JOIN families f ON f.id = fr.family_id '
        . 'WHERE p.document_number = :documentNumber AND f.display_name = :displayName'
    );
    $representativeFlowRow->execute([
        ':documentNumber' => 'COMPOSITE-REP-SUCCESS',
        ':displayName' => 'MariaDB Composite Representative Family',
    ]);
    $representativePhysical = $representativeFlowRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativePhysical !== false
        && (int) $representativePhysical['person_id'] === $representativeFlowOutput->person->id
        && (int) $representativePhysical['representative_id']
            === $representativeFlowOutput->representative->id
        && (int) $representativePhysical['family_id'] === $representativeFlowOutput->family->id
        && (int) $representativePhysical['membership_id'] > 0
        && (int) $representativePhysical['membership_representative_id']
            === $representativeFlowOutput->representative->id
        && (int) $representativePhysical['relationship_type_id'] === $generatedRelationshipTypeId
        && (int) $representativePhysical['is_primary'] === 1
        && $representativePhysical['ended_at'] === null
        && !$connectionA->inTransaction(),
        'Composite Representative flow did not commit Person, role, Family and primary membership.'
    );

    $representativeRollbackRejected = false;
    try {
        $representativeFlow->handle(
            new CreateRepresentativeFamilyInput(
                firstName: 'Composite',
                middleName: 'MariaDB',
                firstSurname: 'Representative',
                secondSurname: 'Rollback',
                documentTypeId: 1,
                documentNumber: 'COMPOSITE-REP-ROLLBACK',
                birthDate: new DateTimeImmutable('1986-05-06', new DateTimeZone('UTC')),
                sexId: 1,
                maritalStatusId: null,
                educationLevelId: null,
                email: null,
                mobilePhone: null,
                landlinePhone: null,
                personStatus: PersonStatus::Active,
                occupation: null,
                companyName: null,
                position: null,
                workPhone: null,
                workEmail: null,
                representativeStatus: RepresentativeStatus::Active,
                displayName: 'MariaDB Composite Representative Rollback',
                familyStatus: FamilyStatus::Active,
                relationshipTypeId: 999999999,
                startedAt: new DateTimeImmutable('2026-08-10 12:00:00', new DateTimeZone('UTC')),
            ),
            $compositeToday,
        );
    } catch (RelationshipTypeNotFound) {
        $representativeRollbackRejected = true;
    }
    $representativeRollbackCounts = $identity->prepare(
        'SELECT '
        . '(SELECT COUNT(*) FROM persons WHERE document_number = :personDocumentNumber) AS persons_count, '
        . '(SELECT COUNT(*) FROM representatives r INNER JOIN persons p ON p.id = r.person_id '
        . 'WHERE p.document_number = :representativeDocumentNumber) AS representatives_count, '
        . '(SELECT COUNT(*) FROM families WHERE display_name = :familyDisplayName) AS families_count, '
        . '(SELECT COUNT(*) FROM family_representatives fr INNER JOIN families f ON f.id = fr.family_id '
        . 'WHERE f.display_name = :membershipDisplayName) AS memberships_count'
    );
    $representativeRollbackCounts->execute([
        ':personDocumentNumber' => 'COMPOSITE-REP-ROLLBACK',
        ':representativeDocumentNumber' => 'COMPOSITE-REP-ROLLBACK',
        ':familyDisplayName' => 'MariaDB Composite Representative Rollback',
        ':membershipDisplayName' => 'MariaDB Composite Representative Rollback',
    ]);
    $representativeRollbackRows = $representativeRollbackCounts->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeRollbackRejected
        && $representativeRollbackRows !== false
        && (int) $representativeRollbackRows['persons_count'] === 0
        && (int) $representativeRollbackRows['representatives_count'] === 0
        && (int) $representativeRollbackRows['families_count'] === 0
        && (int) $representativeRollbackRows['memberships_count'] === 0
        && !$connectionA->inTransaction(),
        'Composite Representative failure did not roll back every inserted row.'
    );

    $studentFlow = new CreateStudentInFamily(
        $transactions,
        new GetFamily($familyRepository),
        $createPerson,
        $createStudent,
        new AddStudentToFamily($familyRepository, $studentRepository),
    );
    $studentFlowOutput = $studentFlow->handle(
        new CreateStudentInFamilyInput(
            familyId: $generatedFamilyId->value(),
            firstName: 'Composite',
            middleName: 'MariaDB',
            firstSurname: 'Student',
            secondSurname: 'Success',
            documentTypeId: 1,
            documentNumber: 'COMPOSITE-STUDENT-SUCCESS',
            birthDate: new DateTimeImmutable('2015-06-07', new DateTimeZone('UTC')),
            sexId: 1,
            maritalStatusId: null,
            educationLevelId: null,
            email: null,
            mobilePhone: null,
            landlinePhone: null,
            personStatus: PersonStatus::Active,
            institutionalCode: 'COMPOSITE-STUDENT-SUCCESS',
            admissionDate: new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            studentStatus: StudentStatus::Active,
            startedAt: new DateTimeImmutable('2026-08-10 13:14:15+02:00'),
        ),
        $compositeToday,
    );
    $studentFlowRow = $identity->prepare(
        'SELECT p.id AS person_id, s.id AS student_id, fs.id AS membership_id, '
        . 'fs.family_id, fs.student_id AS membership_student_id, fs.ended_at '
        . 'FROM persons p INNER JOIN students s ON s.person_id = p.id '
        . 'INNER JOIN family_students fs ON fs.student_id = s.id '
        . 'WHERE p.document_number = :documentNumber AND s.institutional_code = :institutionalCode'
    );
    $studentFlowRow->execute([
        ':documentNumber' => 'COMPOSITE-STUDENT-SUCCESS',
        ':institutionalCode' => 'COMPOSITE-STUDENT-SUCCESS',
    ]);
    $studentPhysical = $studentFlowRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $studentPhysical !== false
        && (int) $studentPhysical['person_id'] === $studentFlowOutput->person->id
        && (int) $studentPhysical['student_id'] === $studentFlowOutput->student->id
        && (int) $studentPhysical['membership_id'] > 0
        && (int) $studentPhysical['family_id'] === $generatedFamilyId->value()
        && (int) $studentPhysical['membership_student_id'] === $studentFlowOutput->student->id
        && $studentPhysical['ended_at'] === null
        && count($studentFlowOutput->family->students) === 2
        && !$connectionA->inTransaction(),
        'Composite Student flow did not commit Person, role and active Family membership with history.'
    );

    $studentRollbackFailure = new RuntimeException('simulated physical FamilyStudent restriction');
    $failingFamilyRepository = new class(
        $familyRepository,
        $studentRollbackFailure,
    ) implements FamilyRepository {
        public function __construct(
            private readonly FamilyRepository $delegate,
            private readonly Throwable $failure,
        ) {
        }

        public function findById(FamilyId $id): ?Family
        {
            return $this->delegate->findById($id);
        }

        public function findActiveByRepresentativeId(
            FamilyRepresentativeReference $representativeId,
        ): array {
            return $this->delegate->findActiveByRepresentativeId($representativeId);
        }

        public function findActiveByStudentId(FamilyStudentReference $studentId): ?Family
        {
            return $this->delegate->findActiveByStudentId($studentId);
        }

        public function save(Family $family): Family
        {
            $this->delegate->save($family);
            throw $this->failure;
        }
    };
    $studentRollbackFlow = new CreateStudentInFamily(
        $transactions,
        new GetFamily($failingFamilyRepository),
        $createPerson,
        $createStudent,
        new AddStudentToFamily($failingFamilyRepository, $studentRepository),
    );
    $familyStateBeforeStudentRollback = mariaDbFamilyPhysicalState(
        $identity,
        $generatedFamilyId->value(),
    );
    $caughtStudentRollbackFailure = null;
    try {
        $studentRollbackFlow->handle(
            new CreateStudentInFamilyInput(
                familyId: $generatedFamilyId->value(),
                firstName: 'Composite',
                middleName: 'MariaDB',
                firstSurname: 'Student',
                secondSurname: 'Rollback',
                documentTypeId: 1,
                documentNumber: 'COMPOSITE-STUDENT-ROLLBACK',
                birthDate: new DateTimeImmutable('2014-07-08', new DateTimeZone('UTC')),
                sexId: 1,
                maritalStatusId: null,
                educationLevelId: null,
                email: null,
                mobilePhone: null,
                landlinePhone: null,
                personStatus: PersonStatus::Active,
                institutionalCode: 'COMPOSITE-STUDENT-ROLLBACK',
                admissionDate: new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
                studentStatus: StudentStatus::Active,
                startedAt: new DateTimeImmutable('2026-08-10 14:15:16', new DateTimeZone('UTC')),
            ),
            $compositeToday,
        );
    } catch (Throwable $exception) {
        $caughtStudentRollbackFailure = $exception;
    }
    $studentRollbackCounts = $identity->prepare(
        'SELECT '
        . '(SELECT COUNT(*) FROM persons WHERE document_number = :documentNumber) AS persons_count, '
        . '(SELECT COUNT(*) FROM students WHERE institutional_code = :studentCode) AS students_count, '
        . '(SELECT COUNT(*) FROM family_students fs INNER JOIN students s ON s.id = fs.student_id '
        . 'WHERE s.institutional_code = :membershipCode) AS memberships_count'
    );
    $studentRollbackCounts->execute([
        ':documentNumber' => 'COMPOSITE-STUDENT-ROLLBACK',
        ':studentCode' => 'COMPOSITE-STUDENT-ROLLBACK',
        ':membershipCode' => 'COMPOSITE-STUDENT-ROLLBACK',
    ]);
    $studentRollbackRows = $studentRollbackCounts->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $caughtStudentRollbackFailure === $studentRollbackFailure
        && $studentRollbackRows !== false
        && (int) $studentRollbackRows['persons_count'] === 0
        && (int) $studentRollbackRows['students_count'] === 0
        && (int) $studentRollbackRows['memberships_count'] === 0
        && mariaDbFamilyPhysicalState($identity, $generatedFamilyId->value())
            === $familyStateBeforeStudentRollback
        && !$connectionA->inTransaction(),
        'Composite Student failure did not restore Person, Student and existing Family state.'
    );

    $representativeUserPerson = $personRepository->save(new Person(
        null,
        new PersonalName('E007', null, 'Representative', 'User'),
        new Identification(1, 'E007-LOGIN-OLD'),
        new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $personToday,
    ));
    $representativeUserPersonId = $representativeUserPerson->id();
    assertIntegration(
        $representativeUserPersonId !== null && $representativeUserPersonId->value() > 0,
        'MariaDB did not generate the E007 Representative Person identity.'
    );
    $representativeUserRole = $representativeRepository->save(new Representative(
        null,
        new RepresentativePersonId($representativeUserPersonId->value()),
        null,
        RepresentativeStatus::Active,
    ));
    $representativeUserRoleId = $representativeUserRole->id();
    assertIntegration(
        $representativeUserRoleId !== null && $representativeUserRoleId->value() > 0,
        'MariaDB did not generate the E007 Representative role identity.'
    );

    $representativeUsers = new PdoUserRepository($managerA);
    $representativePasswordHasher = new NativePasswordHasher();
    $representativePasswordPolicy = new RepresentativePasswordPolicy();
    $createRepresentativeUser = new CreateRepresentativeUser(
        $representativeRepository,
        $personRepository,
        $representativeUsers,
        $representativePasswordHasher,
        $representativePasswordPolicy,
    );
    $initialRepresentativePassword = 'abcde';
    $representativeUserOutput = $createRepresentativeUser->handle(
        new CreateRepresentativeUserInput(
            $representativeUserRoleId->value(),
            $initialRepresentativePassword,
            UserStatus::Active,
        )
    );
    $generatedRepresentativeUserId = $representativeUserOutput->userId;
    $persistedRepresentativeUser = $representativeUsers->findByPersonId(
        new UserPersonId($representativeUserPersonId->value())
    );
    $representativeUserRow = $identity->prepare(
        'SELECT u.id, u.person_id, u.login_identifier, u.normalized_login_identifier, '
        . 'u.password_hash, u.failed_login_attempts, u.locked_at, u.last_access_at, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM users u INNER JOIN statuses s ON s.id = u.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id WHERE u.id = :id'
    );
    $representativeUserRow->execute([':id' => $generatedRepresentativeUserId]);
    $representativeUserPhysical = $representativeUserRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $generatedRepresentativeUserId > 0
        && $persistedRepresentativeUser?->id()?->value() === $generatedRepresentativeUserId
        && $persistedRepresentativeUser?->personId()->value() === $representativeUserPersonId->value()
        && $persistedRepresentativeUser?->loginIdentifier()->value() === 'e007-login-old'
        && $representativeUserPhysical !== false
        && (int) $representativeUserPhysical['person_id'] === $representativeUserPersonId->value()
        && $representativeUserPhysical['login_identifier'] === 'e007-login-old'
        && $representativeUserPhysical['normalized_login_identifier'] === 'e007-login-old'
        && $representativeUserPhysical['password_hash'] !== $initialRepresentativePassword
        && $representativePasswordHasher->verify(
            $initialRepresentativePassword,
            (string) $representativeUserPhysical['password_hash'],
        )
        && (int) $representativeUserPhysical['failed_login_attempts'] === 0
        && $representativeUserPhysical['locked_at'] === null
        && $representativeUserPhysical['last_access_at'] === null
        && $representativeUserPhysical['status_type_code'] === 'USER_STATUS'
        && $representativeUserPhysical['status_code'] === 'ACTIVE',
        'Representative User provisioning did not use MariaDB identity hashing exact status or complete reload.'
    );

    $perPersonUserUniqueRejected = false;
    try {
        $representativeUsers->save(new User(
            null,
            new UserPersonId($representativeUserPersonId->value()),
            new LoginIdentifier('e007-other-login'),
            new PasswordHash($representativePasswordHasher->hash('other-password')),
            UserStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $perPersonUserUniqueRejected = true;
    }
    assertIntegration(
        $perPersonUserUniqueRejected,
        'MariaDB did not enforce one User per Person.'
    );

    $normalizedLoginUniqueRejected = false;
    try {
        $representativeUsers->save(new User(
            null,
            new UserPersonId($secondGeneratedPersonId->value()),
            new LoginIdentifier('E007-LOGIN-OLD'),
            new PasswordHash($representativePasswordHasher->hash('other-password')),
            UserStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $normalizedLoginUniqueRejected = true;
    }
    assertIntegration(
        $normalizedLoginUniqueRejected,
        'MariaDB did not enforce global normalized Representative login uniqueness.'
    );

    $authenticationStateInstant = new DateTimeImmutable('2026-08-01 08:09:10', new DateTimeZone('UTC'));
    $persistedRepresentativeUser->recordSuccessfulAuthentication($authenticationStateInstant);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $persistedRepresentativeUser->recordFailedLogin(
            $authenticationStateInstant->modify(sprintf('+%d minutes', $attempt)),
            5,
        );
    }
    $representativeUsers->save($persistedRepresentativeUser);
    $beforePasswordChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    $newRepresentativePassword = '12345';
    $changedPasswordOutput = (new ChangeRepresentativeUserPassword(
        $representativeRepository,
        $representativeUsers,
        $representativePasswordHasher,
        $representativePasswordPolicy,
    ))->handle(new ChangeRepresentativeUserPasswordInput(
        $representativeUserRoleId->value(),
        $newRepresentativePassword,
    ));
    $afterPasswordChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    assertIntegration(
        $changedPasswordOutput->userId === $generatedRepresentativeUserId
        && $afterPasswordChange?->personId()->value() === $beforePasswordChange?->personId()->value()
        && $afterPasswordChange?->loginIdentifier()->value()
            === $beforePasswordChange?->loginIdentifier()->value()
        && $afterPasswordChange?->status() === $beforePasswordChange?->status()
        && $afterPasswordChange?->failedLoginAttempts()
            === $beforePasswordChange?->failedLoginAttempts()
        && $afterPasswordChange?->lockedAt()?->getTimestamp()
            === $beforePasswordChange?->lockedAt()?->getTimestamp()
        && $afterPasswordChange?->lastAccessAt()?->getTimestamp()
            === $beforePasswordChange?->lastAccessAt()?->getTimestamp()
        && $afterPasswordChange !== null
        && $representativePasswordHasher->verify(
            $newRepresentativePassword,
            $afterPasswordChange->passwordHash()->value(),
        )
        && !$representativePasswordHasher->verify(
            $initialRepresentativePassword,
            $afterPasswordChange->passwordHash()->value(),
        ),
        'Administrative password change did not preserve Representative User authentication state.'
    );

    $updateRepresentativePerson = new UpdatePersonWithRepresentativeUserSync(
        new UpdatePerson($personRepository),
        $personRepository,
        $representativeUsers,
        $representativeRepository,
        $transactions,
    );
    $beforeDocumentChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    $updatedRepresentativePerson = $updateRepresentativePerson->handle(
        new UpdatePersonInput(
            $representativeUserPersonId->value(),
            'E007',
            null,
            'Representative',
            'User',
            1,
            'E007-LOGIN-NEW',
            new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        ),
        $personToday,
    );
    $afterDocumentChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    assertIntegration(
        $updatedRepresentativePerson->documentNumber === 'E007-LOGIN-NEW'
        && $representativeUsers->findByLoginIdentifier(
            new LoginIdentifier('E007-LOGIN-NEW')
        )?->id()?->value() === $generatedRepresentativeUserId
        && $representativeUsers->findByLoginIdentifier(
            new LoginIdentifier('E007-LOGIN-OLD')
        ) === null
        && $afterDocumentChange?->id()?->value() === $beforeDocumentChange?->id()?->value()
        && $afterDocumentChange?->personId()->value()
            === $beforeDocumentChange?->personId()->value()
        && $afterDocumentChange?->passwordHash()->value()
            === $beforeDocumentChange?->passwordHash()->value()
        && $afterDocumentChange?->status() === $beforeDocumentChange?->status()
        && $afterDocumentChange?->failedLoginAttempts()
            === $beforeDocumentChange?->failedLoginAttempts()
        && $afterDocumentChange?->lockedAt()?->getTimestamp()
            === $beforeDocumentChange?->lockedAt()?->getTimestamp()
        && $afterDocumentChange?->lastAccessAt()?->getTimestamp()
            === $beforeDocumentChange?->lastAccessAt()?->getTimestamp(),
        'DocumentNumber and Representative login did not synchronize while preserving User state.'
    );

    $identificationRemovalRejected = false;
    try {
        $updateRepresentativePerson->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007',
                null,
                'Representative',
                'User',
                null,
                null,
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                null,
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (RepresentativeUserRequiresIdentification) {
        $identificationRemovalRejected = true;
    }
    assertIntegration(
        $identificationRemovalRejected
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new',
        'Representative User allowed removal of Person Identification.'
    );

    $loginCollisionRejected = false;
    try {
        $updateRepresentativePerson->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007',
                null,
                'Representative',
                'User',
                1,
                'ADMIN',
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                null,
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (RepresentativeLoginIdentifierAlreadyUsed) {
        $loginCollisionRejected = true;
    }
    assertIntegration(
        $loginCollisionRejected
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new',
        'Representative login collision did not leave Person and User unchanged.'
    );

    $postPersonFailure = new RuntimeException('simulated E007 User save failure');
    $failingRepresentativeUsers = new class(
        $representativeUsers,
        $postPersonFailure,
    ) implements UserRepository {
        public function __construct(
            private readonly UserRepository $delegate,
            private readonly Throwable $failure,
        ) {
        }

        public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
        {
            return $this->delegate->findByLoginIdentifier($identifier);
        }

        public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
        {
            return $this->delegate->findByLoginIdentifierForUpdate($identifier);
        }

        public function findById(\App\IdentityAccess\Domain\ValueObject\UserId $id): ?User
        {
            return $this->delegate->findById($id);
        }

        public function findByPersonId(UserPersonId $personId): ?User
        {
            return $this->delegate->findByPersonId($personId);
        }

        public function save(User $user): User
        {
            throw $this->failure;
        }
    };
    $failingDocumentUpdate = new UpdatePersonWithRepresentativeUserSync(
        new UpdatePerson($personRepository),
        $personRepository,
        $failingRepresentativeUsers,
        $representativeRepository,
        $transactions,
    );
    $caughtPostPersonFailure = null;
    try {
        $failingDocumentUpdate->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007 Partial',
                null,
                'Representative',
                'User',
                1,
                'E007-ROLLBACK',
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                null,
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (Throwable $exception) {
        $caughtPostPersonFailure = $exception;
    }
    assertIntegration(
        $caughtPostPersonFailure === $postPersonFailure
        && $personRepository->findById($representativeUserPersonId)?->personalName()->firstName()
            === 'E007'
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new'
        && !$connectionA->inTransaction(),
        'Failure after Person update did not roll back both Representative login states.'
    );

    $authenticationSession = new class implements SessionManager {
        public ?int $userId = null;
        /** @var array<string, mixed> */
        private array $values = [];

        public function regenerateForUser(int $userId): void
        {
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
            $this->userId = null;
            $this->values = [];
        }
    };
    $authenticationEvents = new class implements SecurityEventLogger {
        public function record(string $event): void
        {
        }
    };
    $authenticationClock = new class(
        new DateTimeImmutable('2026-08-01 08:30:00', new DateTimeZone('UTC'))
    ) implements Clock {
        public function __construct(private readonly DateTimeImmutable $now)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
    $authenticateRepresentative = new AuthenticateUser(
        $representativeUsers,
        $representativePasswordHasher,
        $authenticationSession,
        new PdoTransactionManager($managerA),
        $authenticationClock,
        $authenticationEvents,
        new AuthenticationPolicy(5, 900),
    );
    $oldLoginResult = $authenticateRepresentative->handle(
        'E007-LOGIN-OLD',
        $newRepresentativePassword,
    );
    $newLoginResult = $authenticateRepresentative->handle(
        'E007-LOGIN-NEW',
        $newRepresentativePassword,
    );
    assertIntegration(
        !$oldLoginResult->isSuccessful()
        && $newLoginResult->isSuccessful()
        && $authenticationSession->userId === $generatedRepresentativeUserId,
        'Representative authentication did not move exclusively to the synchronized DocumentNumber.'
    );

    $representativeAccessUserState = $identity->prepare(
        'SELECT * FROM users WHERE id = :id'
    );
    $representativeAccessUserState->execute([':id' => $generatedRepresentativeUserId]);
    $representativeAccessUserBefore = $representativeAccessUserState->fetch(PDO::FETCH_ASSOC);
    $representativeAccessRoleState = $identity->prepare(
        'SELECT * FROM representatives WHERE id = :id'
    );
    $representativeAccessRoleState->execute([':id' => $representativeUserRoleId->value()]);
    $representativeAccessRoleBefore = $representativeAccessRoleState->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeAccessUserBefore !== false && $representativeAccessRoleBefore !== false,
        'Representative access read-only snapshot could not load the persisted identities.'
    );

    $getAuthenticatedRepresentative = new GetAuthenticatedRepresentative(
        new GetAuthenticatedUser($authenticationSession, $representativeUsers),
        $representativeRepository,
    );
    $authenticatedRepresentative = $getAuthenticatedRepresentative->handle();

    $representativeAccessUserState->execute([':id' => $generatedRepresentativeUserId]);
    $representativeAccessUserAfter = $representativeAccessUserState->fetch(PDO::FETCH_ASSOC);
    $representativeAccessRoleState->execute([':id' => $representativeUserRoleId->value()]);
    $representativeAccessRoleAfter = $representativeAccessRoleState->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $authenticatedRepresentative?->userId === $generatedRepresentativeUserId
        && $authenticatedRepresentative->personId === $representativeUserPersonId->value()
        && $authenticatedRepresentative->representativeId === $representativeUserRoleId->value()
        && $authenticatedRepresentative->loginIdentifier === 'e007-login-new'
        && $representativeAccessUserAfter === $representativeAccessUserBefore
        && $representativeAccessRoleAfter === $representativeAccessRoleBefore,
        'Authenticated Representative resolution did not preserve exact identity or read-only state.'
    );

    $nonRepresentativeAccessPerson = $personRepository->save(new Person(
        null,
        new PersonalName('Access', null, 'Nonrepresentative', null),
        null,
        new DateTimeImmutable('2002-02-02', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $personToday,
    ));
    $nonRepresentativeAccessPersonId = $nonRepresentativeAccessPerson->id();
    assertIntegration(
        $nonRepresentativeAccessPersonId !== null && $nonRepresentativeAccessPersonId->value() > 0,
        'MariaDB did not generate the non-Representative Person identity for access resolution.'
    );
    $nonRepresentativeAccessUser = $representativeUsers->save(new User(
        null,
        new UserPersonId($nonRepresentativeAccessPersonId->value()),
        new LoginIdentifier('E007-ACCESS-NON-REPRESENTATIVE'),
        new PasswordHash($representativePasswordHasher->hash('access-resolution-probe')),
        UserStatus::Active,
    ));
    $nonRepresentativeAccessUserId = $nonRepresentativeAccessUser->id();
    assertIntegration(
        $nonRepresentativeAccessUserId !== null && $nonRepresentativeAccessUserId->value() > 0,
        'MariaDB did not generate the non-Representative User identity for access resolution.'
    );
    $authenticationSession->userId = $nonRepresentativeAccessUserId->value();
    assertIntegration(
        $getAuthenticatedRepresentative->handle() === null,
        'Authenticated User without Representative unexpectedly received Representative Access.'
    );
    echo "PASS MySQL authenticated Representative access resolution read-only identity and fail-closed behavior\n";

    assertIntegration(
        $representativePasswordHasher->verify('DisposableAdminPassword', $hash)
        && $representativeUsers->findByLoginIdentifier(new LoginIdentifier('admin')) !== null,
        'Existing administrator credential compatibility was not preserved.'
    );

    $managerB = new ConnectionManager(new ConnectionFactory(), $databaseConfig);
    $connectionB = $managerB->connection();
    assertIntegration(
        $connectionA->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00',
        'ConnectionFactory did not establish the UTC SQL convention.'
    );

    $knownLockInstant = new DateTimeImmutable('2026-07-31 07:34:56-05:00');
    $repositoryA = new PdoUserRepository($managerA);
    $utcUser = $repositoryA->findByLoginIdentifier(new LoginIdentifier('admin'));
    assertIntegration($utcUser !== null, 'UTC repository probe could not load User.');
    $utcUser->recordFailedLogin($knownLockInstant, 5);
    $repositoryA->save($utcUser);
    $reloadedUtcUser = $repositoryA->findByLoginIdentifier(new LoginIdentifier('admin'));
    assertIntegration(
        $reloadedUtcUser?->lockedAt()?->getTimestamp() === $knownLockInstant->getTimestamp(),
        'Repository locked_at did not preserve the expected UTC instant.'
    );

    $unrelatedSqlError = new PDOException("Table 'missing' doesn't exist");
    $unrelatedSqlError->errorInfo = ['42S02', 1146, "Table 'missing' doesn't exist"];
    assertIntegration(
        !isExpectedMariaDbLockException($unrelatedSqlError),
        'An unrelated SQL exception was misclassified as a concurrency lock.'
    );
    $connectionA->beginTransaction();
    (new PdoUserRepository($managerA))->findByLoginIdentifierForUpdate(
        new LoginIdentifier('admin')
    );
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $connectionB->beginTransaction();
    $lockBlocked = false;
    try {
        (new PdoUserRepository($managerB))->findByLoginIdentifierForUpdate(
            new LoginIdentifier('admin')
        );
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Concurrent User load failed for a reason other than a MariaDB lock conflict.',
                previous: $exception
            );
        }
        $lockBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }
    assertIntegration($lockBlocked, 'Concurrent User load was not protected by a row lock.');

    echo "PASS MySQL clean migration creates the exact 36-table domain baseline plus migrations metadata\n";
    echo "PASS MySQL approved status seed baseline\n";
    echo "PASS MySQL AdminSeeder preserves existing credentials and status\n";
    echo "PASS MySQL UTC repository locked_at persistence\n";
    echo "PASS MySQL concurrent User row locking with specific MariaDB error classification\n";
    echo "PASS MySQL database-generated Person identities and complete aggregate reconstruction\n";
    echo "PASS MySQL Person normalized identification lookup and uniqueness\n";
    echo "PASS MySQL Person update, nullable fields and GENERAL_STATUS mapping\n";
    echo "PASS MySQL Representative AUTO_INCREMENT lookup and EmploymentInformation persistence\n";
    echo "PASS MySQL Representative update uniqueness and exact GENERAL_STATUS mapping\n";
    echo "PASS MySQL Student AUTO_INCREMENT lookups and AdmissionDate reconstruction\n";
    echo "PASS MySQL Student administrative update uniqueness collation and exact GENERAL_STATUS mapping\n";
    echo "PASS MySQL Family atomic AUTO_INCREMENT creation and complete Aggregate reconstruction\n";
    echo "PASS MySQL Family Representative and Student active lookups and historical membership\n";
    echo "PASS MySQL Family physical uniqueness UTC status mapping and transactional rollback\n";
    echo "PASS MySQL Family delivery relationship lookup active options and exact statuses\n";
    echo "PASS MySQL composite Representative Person role Family atomic commit and rollback\n";
    echo "PASS MySQL composite Student Person role membership atomic commit and rollback\n";
    echo "PASS MySQL Representative User AUTO_INCREMENT provisioning lookup hashing and physical uniqueness\n";
    echo "PASS MySQL Representative administrative password change preserves authentication state\n";
    echo "PASS MySQL Representative document login synchronization conflict rollback and authentication\n";
    echo "PASS MySQL partial disposable database creation cleanup\n";
} finally {
    $cleanupFailures = dropDisposableDatabases($server, $createdDatabases);
    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            'Disposable database cleanup failed after all drops were attempted: ' . implode('; ', $cleanupFailures)
        );
    }
}
