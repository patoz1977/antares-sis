<?php

declare(strict_types=1);

use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
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

function createCurrentIdentitySchema(PDO $connection): void
{
    $connection->exec(
        'CREATE TABLE persons ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'PRIMARY KEY (id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $connection->exec(
        'CREATE TABLE status_types ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'code VARCHAR(100) NOT NULL, '
        . 'PRIMARY KEY (id), '
        . 'UNIQUE KEY uq_status_types_code (code)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $connection->exec(
        'CREATE TABLE statuses ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'status_type_id BIGINT UNSIGNED NOT NULL, '
        . 'code VARCHAR(100) NOT NULL, '
        . 'PRIMARY KEY (id), '
        . 'UNIQUE KEY uq_statuses_type_code (status_type_id, code), '
        . 'CONSTRAINT fk_statuses_type FOREIGN KEY (status_type_id) '
        . 'REFERENCES status_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $connection->exec(
        'CREATE TABLE users ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . 'person_id BIGINT UNSIGNED NOT NULL, '
        . 'login_identifier VARCHAR(254) NOT NULL, '
        . 'normalized_login_identifier VARCHAR(254) NOT NULL, '
        . 'password_hash VARCHAR(255) NOT NULL, '
        . 'status_id BIGINT UNSIGNED NOT NULL, '
        . 'last_access_at TIMESTAMP NULL DEFAULT NULL, '
        . 'failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
        . 'locked_at TIMESTAMP NULL DEFAULT NULL, '
        . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
        . 'PRIMARY KEY (id), '
        . 'UNIQUE KEY uq_users_person (person_id), '
        . 'UNIQUE KEY uq_users_normalized_login (normalized_login_identifier), '
        . 'KEY idx_users_status_locked (status_id, locked_at), '
        . 'CONSTRAINT chk_users_normalized_login '
        . 'CHECK (normalized_login_identifier = LOWER(TRIM(normalized_login_identifier))), '
        . 'CONSTRAINT fk_users_person FOREIGN KEY (person_id) '
        . 'REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT, '
        . 'CONSTRAINT fk_users_status FOREIGN KEY (status_id) '
        . 'REFERENCES statuses(id) ON DELETE RESTRICT ON UPDATE RESTRICT'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

$requiredEnvironment = [
    'E0041_DB_HOST',
    'E0041_DB_PORT',
    'E0041_DB_USERNAME',
    'E0041_DB_PASSWORD',
    'E0041_DB_PREFIX',
];

foreach ($requiredEnvironment as $environmentName) {
    if (getenv($environmentName) === false) {
        throw new RuntimeException(
            sprintf('%s is required; .env fallback is intentionally forbidden.', $environmentName)
        );
    }
}

if (getenv('E0041_DB_ALLOW_DISPOSABLE') !== '1') {
    throw new RuntimeException(
        'E0041_DB_ALLOW_DISPOSABLE=1 is required to authorize disposable databases.'
    );
}

$host = (string) getenv('E0041_DB_HOST');
$port = (int) getenv('E0041_DB_PORT');
$username = (string) getenv('E0041_DB_USERNAME');
$password = (string) getenv('E0041_DB_PASSWORD');
$databasePrefix = (string) getenv('E0041_DB_PREFIX');
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
    createCurrentIdentitySchema($identity);
    $identity->exec("INSERT INTO persons (id) VALUES (1)");
    $identity->exec("INSERT INTO status_types (id, code) VALUES (1, 'USER_STATUS')");
    $identity->exec(
        "INSERT INTO statuses (id, status_type_id, code) VALUES "
        . "(1, 1, 'ACTIVE'), (2, 1, 'DISABLED')"
    );
    $hash = password_hash('DisposableAdminPassword', PASSWORD_DEFAULT);
    assertIntegration(is_string($hash), 'Unable to create disposable password hash.');
    $insert = $identity->prepare(
        'INSERT INTO users '
        . '(person_id, login_identifier, normalized_login_identifier, password_hash, status_id, failed_login_attempts) '
        . 'VALUES (1, :loginIdentifier, :normalizedLoginIdentifier, :passwordHash, 2, 4)'
    );
    $insert->execute([
        ':loginIdentifier' => 'admin',
        ':normalizedLoginIdentifier' => 'admin',
        ':passwordHash' => $hash,
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
        $preserved !== false && (int) $preserved['status_id'] === 2,
        'AdminSeeder changed an existing User status.'
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

    echo "PASS MySQL current User schema fixture excludes discarded columns\n";
    echo "PASS MySQL AdminSeeder preserves existing credentials and status\n";
    echo "PASS MySQL UTC repository locked_at persistence\n";
    echo "PASS MySQL concurrent User row locking with specific MariaDB error classification\n";
    echo "PASS MySQL partial disposable database creation cleanup\n";
} finally {
    $cleanupFailures = dropDisposableDatabases($server, $createdDatabases);
    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            'Disposable database cleanup failed after all drops were attempted: ' . implode('; ', $cleanupFailures)
        );
    }
}
