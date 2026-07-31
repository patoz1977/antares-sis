<?php

declare(strict_types=1);

use Database\Seeders\AdminSeeder;
use Database\Seeders\StatusSeeder;
use Database\Seeders\StatusTypeSeeder;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/seeders/StatusTypeSeeder.php';
require dirname(__DIR__) . '/database/seeders/StatusSeeder.php';
require dirname(__DIR__) . '/database/seeders/AdminSeeder.php';

function migrationClass(string $file): string
{
    $parts = explode('_', pathinfo($file, PATHINFO_FILENAME));
    array_shift($parts);

    return 'Create' . implode('', array_map(
        static fn (string $part): string => str_replace(' ', '', ucwords(str_replace('_', ' ', $part))),
        array_values(array_filter($parts, static fn (string $part): bool => $part !== 'create'))
    ));
}

function runMigrations(PDO $connection, int $maximumVersion): void
{
    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files);

    foreach ($files as $path) {
        $file = basename($path);
        if ((int) substr($file, 0, 3) > $maximumVersion) {
            continue;
        }

        require_once $path;
        $class = migrationClass($file);
        (new $class())->up($connection);
    }
}

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$requiredEnvironment = [
    'E0041_DB_HOST',
    'E0041_DB_PORT',
    'E0041_DB_USERNAME',
    'E0041_DB_PASSWORD',
    'E0041_DB_DATABASE_PREFIX',
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
$databasePrefix = (string) getenv('E0041_DB_DATABASE_PREFIX');
$charset = 'utf8mb4';

assertIntegration(
    preg_match('/^[a-z][a-z0-9_]{2,30}$/', $databasePrefix) === 1,
    'E0041_DB_DATABASE_PREFIX must be a safe lowercase disposable prefix.'
);
assertIntegration(
    strtolower($databasePrefix) !== 'ueant' && !str_contains(strtolower($databasePrefix), 'ueant'),
    'UEAnt is explicitly forbidden for E004.1 integration tests.'
);

$suffix = bin2hex(random_bytes(5));
$freshDatabase = $databasePrefix . '_fresh_' . $suffix;
$incrementalDatabase = $databasePrefix . '_incremental_' . $suffix;
$duplicateDatabase = $databasePrefix . '_duplicate_' . $suffix;

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

foreach ([$freshDatabase, $incrementalDatabase, $duplicateDatabase] as $database) {
    assertIntegration(
        preg_match('/^[a-z][a-z0-9_]{2,30}_(fresh|incremental|duplicate)_[a-f0-9]{10}$/', $database) === 1,
        'Unsafe disposable database name.'
    );
    $server->exec(sprintf(
        'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $database
    ));
}

try {
    $fresh = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $freshDatabase, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    runMigrations($fresh, 9);
    require_once dirname(__DIR__) . '/database/migrations/014_create_identity_access_baseline.php';
    (new CreateIdentityAccessBaseline())->up($fresh);
    (new StatusTypeSeeder())->run($fresh);
    (new StatusSeeder())->run($fresh);
    putenv('E0041_ADMIN_INITIAL_PASSWORD=DisposableAdminPassword');
    (new AdminSeeder())->run($fresh);

    assertIntegration((int) $fresh->query(
        "SELECT COUNT(*) FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'users' "
        . "AND column_name IN ('login_identifier', 'normalized_login_identifier', 'last_access_at', 'locked_at')"
    )->fetchColumn() === 4, 'Fresh migration is missing identity columns.');
    assertIntegration(
        (int) $fresh->query("SELECT COUNT(*) FROM users WHERE normalized_login_identifier = 'admin'")->fetchColumn() === 1,
        'Fresh seed did not create the normalized administrator login.'
    );
    $seededUser = $fresh->query(
        "SELECT id, password_hash, status_id FROM users WHERE normalized_login_identifier = 'admin'"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration($seededUser !== false, 'Seeded administrator could not be loaded.');
    $disabledStatusId = (int) $fresh->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'DISABLED'"
    )->fetchColumn();
    $fresh->exec(
        "UPDATE users SET password_hash = 'preserved-hash', status_id = $disabledStatusId "
        . 'WHERE id = ' . (int) $seededUser['id']
    );
    (new AdminSeeder())->run($fresh);
    $reseededUser = $fresh->query(
        'SELECT password_hash, status_id FROM users WHERE id = ' . (int) $seededUser['id']
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $reseededUser !== false && $reseededUser['password_hash'] === 'preserved-hash',
        'Second AdminSeeder execution replaced an existing password hash.'
    );
    assertIntegration(
        (int) $reseededUser['status_id'] === $disabledStatusId,
        'Second AdminSeeder execution changed an existing User status.'
    );

    $incremental = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $incrementalDatabase,
            $charset
        ),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    runMigrations($incremental, 9);
    (new StatusTypeSeeder())->run($incremental);
    (new StatusSeeder())->run($incremental);

    $personStatusId = (int) $incremental->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'PERSON_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $userStatusId = (int) $incremental->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();

    $incremental->exec(
        "INSERT INTO persons (status_id, document_type_id, document_number, first_name, last_name) "
        . "VALUES ($personStatusId, 1, 'LEGACY-1', 'Legacy', 'User')"
    );
    $personId = (int) $incremental->lastInsertId();
    $hash = password_hash('Legacy123!', PASSWORD_DEFAULT);
    $insertUser = $incremental->prepare(
        'INSERT INTO users '
        . '(person_id, status_id, username, email, password_hash, failed_login_attempts, locked_until) '
        . 'VALUES (:personId, :statusId, :username, :email, :passwordHash, 2, '
        . 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))'
    );
    $insertUser->execute([
        ':personId' => $personId,
        ':statusId' => $userStatusId,
        ':username' => ' Legacy.User ',
        ':email' => 'legacy@example.com',
        ':passwordHash' => $hash,
    ]);

    require_once dirname(__DIR__) . '/database/migrations/014_create_identity_access_baseline.php';
    $migration = new CreateIdentityAccessBaseline();
    $migration->up($incremental);
    $incremental->exec(
        "UPDATE users SET failed_login_attempts = 3, locked_at = '2026-07-31 12:34:56' "
        . "WHERE username = ' Legacy.User '"
    );
    $migration->up($incremental);

    $legacy = $incremental->query(
        "SELECT normalized_login_identifier, failed_login_attempts, locked_at, locked_until "
        . "FROM users WHERE username = ' Legacy.User '"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration($legacy !== false, 'Incremental user was not preserved.');
    assertIntegration(
        $legacy['normalized_login_identifier'] === 'legacy.user',
        'Incremental login normalization failed.'
    );
    assertIntegration(
        (int) $legacy['failed_login_attempts'] === 3,
        'Second migration execution overwrote active-model failed attempts.'
    );
    assertIntegration(
        $legacy['locked_at'] === '2026-07-31 12:34:56',
        'Second migration execution overwrote active-model locked_at.'
    );
    assertIntegration($legacy['locked_until'] !== null, 'Legacy locked_until was not retained.');

    try {
        $migration->down($incremental);
        throw new RuntimeException('Forward-only migration unexpectedly allowed rollback.');
    } catch (RuntimeException $exception) {
        assertIntegration(
            str_contains($exception->getMessage(), 'forward-only'),
            'Forward-only migration did not reject rollback explicitly.'
        );
    }

    $databaseConfig = new DatabaseConfig([
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'database' => $incrementalDatabase,
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
    $connectionA->beginTransaction();
    (new PdoUserRepository($managerA))->findByLoginIdentifierForUpdate(
        new LoginIdentifier('legacy.user')
    );
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $connectionB->beginTransaction();
    $lockBlocked = false;
    try {
        (new PdoUserRepository($managerB))->findByLoginIdentifierForUpdate(
            new LoginIdentifier('legacy.user')
        );
    } catch (PDOException) {
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

    $duplicate = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $duplicateDatabase, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    runMigrations($duplicate, 9);
    (new StatusTypeSeeder())->run($duplicate);
    (new StatusSeeder())->run($duplicate);
    $duplicatePersonStatusId = (int) $duplicate->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'PERSON_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $duplicateUserStatusId = (int) $duplicate->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $duplicate->exec(
        "INSERT INTO persons (status_id, document_type_id, document_number, first_name, last_name) VALUES "
        . "($duplicatePersonStatusId, 1, 'DUP-1', 'One', 'User'), "
        . "($duplicatePersonStatusId, 1, 'DUP-2', 'Two', 'User')"
    );
    $personIds = $duplicate->query('SELECT id FROM persons ORDER BY id DESC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    $duplicateInsert = $duplicate->prepare(
        'INSERT INTO users (person_id, status_id, username, email, password_hash) '
        . 'VALUES (:personId, :statusId, :username, :email, :passwordHash)'
    );
    foreach ([[' Duplicate ', 'one@example.com'], ['duplicate', 'two@example.com']] as $index => $identity) {
        $duplicateInsert->execute([
            ':personId' => (int) $personIds[$index],
            ':statusId' => $duplicateUserStatusId,
            ':username' => $identity[0],
            ':email' => $identity[1],
            ':passwordHash' => password_hash('DisposablePassword', PASSWORD_DEFAULT),
        ]);
    }
    try {
        (new CreateIdentityAccessBaseline())->up($duplicate);
        throw new RuntimeException('Duplicate preflight unexpectedly succeeded.');
    } catch (RuntimeException $exception) {
        assertIntegration(
            str_contains($exception->getMessage(), 'duplicate normalized login identifiers'),
            'Duplicate preflight failed for an unexpected reason.'
        );
    }
    assertIntegration(
        (int) $duplicate->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
            . "AND table_name = 'users' AND column_name = 'normalized_login_identifier'"
        )->fetchColumn() === 0,
        'Duplicate preflight left partial schema changes.'
    );

    echo "PASS MySQL fresh Identity schema 001-009 plus 014 with seeders\n";
    echo "PASS MySQL incremental Identity schema 001-009 to 014 with legacy lock\n";
    echo "PASS MySQL migration 014 idempotency\n";
    echo "PASS MySQL UTC convention and concurrent User row locking\n";
    echo "PASS MySQL duplicate preflight leaves schema unchanged\n";
} finally {
    foreach ([$freshDatabase, $incrementalDatabase, $duplicateDatabase] as $database) {
        if (preg_match('/^[a-z][a-z0-9_]{2,30}_(fresh|incremental|duplicate)_[a-f0-9]{10}$/', $database) === 1) {
            $server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        }
    }
}
