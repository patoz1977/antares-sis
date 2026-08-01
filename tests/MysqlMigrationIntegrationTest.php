<?php

declare(strict_types=1);

use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\MigrationRunner;
use Database\Seeders\AdminSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/seeders/AdminSeeder.php';

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function isExpectedMariaDbLockException(PDOException $exception): bool
{
    $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);
    $message = strtolower($exception->errorInfo[2] ?? $exception->getMessage());

    return ($sqlState === 'HY000' && $driverCode === 1205 && str_contains($message, 'lock wait timeout'))
        || ($sqlState === '40001' && $driverCode === 1213 && str_contains($message, 'deadlock'));
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
    $secondPersistedPerson = $personRepository->save(new Person(
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
    ));
    assertIntegration(
        $secondPersistedPerson->id()?->value() === $generatedPersonId->value() + 1,
        'MariaDB did not manage consecutive Person AUTO_INCREMENT identities.'
    );
    $createdAtStatement = $identity->prepare('SELECT created_at FROM persons WHERE id = :id');
    $createdAtStatement->execute([':id' => $generatedPersonId->value()]);
    $createdAt = $createdAtStatement->fetchColumn();
    assertIntegration(
        $personRepository->findById($generatedPersonId)?->personalName()->middleName() === 'Maria',
        'Person repository did not reconstruct the inserted aggregate by ID.'
    );
    assertIntegration(
        $personRepository->findByIdentification(new Identification(1, '  person-100  '))?->id()?->value()
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

    $managerB = new ConnectionManager(new ConnectionFactory(), $databaseConfig);
    $connectionA = $managerA->connection();
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
    echo "PASS MySQL partial disposable database creation cleanup\n";
} finally {
    $cleanupFailures = dropDisposableDatabases($server, $createdDatabases);
    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            'Disposable database cleanup failed after all drops were attempted: ' . implode('; ', $cleanupFailures)
        );
    }
}
