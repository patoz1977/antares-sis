<?php

declare(strict_types=1);

use Database\Seeders\AdminSeeder;
use Database\Seeders\StatusSeeder;
use Database\Seeders\StatusTypeSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/seeders/StatusTypeSeeder.php';
require dirname(__DIR__) . '/database/seeders/StatusSeeder.php';
require dirname(__DIR__) . '/database/seeders/AdminSeeder.php';

/**
 * Destructive only for the two randomly named databases created by this script.
 * The configured application database is never selected or modified.
 */
function loadDatabaseEnvironment(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $values[$key] = trim($value, "\"'");
    }

    return $values;
}

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

$env = loadDatabaseEnvironment(dirname(__DIR__) . '/.env');
$hostOverride = getenv('E0041_DB_HOST');
$host = $hostOverride === false ? ($env['DB_HOST'] ?? '127.0.0.1') : $hostOverride;
$portOverride = getenv('E0041_DB_PORT');
$port = (int) ($portOverride === false ? ($env['DB_PORT'] ?? 3306) : $portOverride);
$usernameOverride = getenv('E0041_DB_USERNAME');
$username = $usernameOverride === false ? ($env['DB_USERNAME'] ?? '') : $usernameOverride;
$passwordOverride = getenv('E0041_DB_PASSWORD');
$password = $passwordOverride === false ? ($env['DB_PASSWORD'] ?? '') : $passwordOverride;
$charset = $env['DB_CHARSET'] ?? 'utf8mb4';
$configuredDatabase = $env['DB_DATABASE'] ?? '';
$suffix = bin2hex(random_bytes(5));
$freshDatabase = 'antares_e0041_fresh_' . $suffix;
$incrementalDatabase = 'antares_e0041_incremental_' . $suffix;

assertIntegration($configuredDatabase !== $freshDatabase, 'Fresh test database collided with configured database.');
assertIntegration($configuredDatabase !== $incrementalDatabase, 'Incremental test database collided with configured database.');

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

foreach ([$freshDatabase, $incrementalDatabase] as $database) {
    assertIntegration(
        preg_match('/^antares_e0041_(fresh|incremental)_[a-f0-9]{10}$/', $database) === 1,
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
    assertIntegration((int) $legacy['failed_login_attempts'] === 5, 'Active legacy lock was not normalized.');
    assertIntegration($legacy['locked_at'] !== null, 'Active legacy lock did not produce locked_at.');
    assertIntegration($legacy['locked_until'] !== null, 'Legacy locked_until was not retained.');

    echo "PASS MySQL fresh Identity schema 001-009 plus 014 with seeders\n";
    echo "PASS MySQL incremental Identity schema 001-009 to 014 with legacy lock\n";
    echo "PASS MySQL migration 014 idempotency\n";
} finally {
    foreach ([$freshDatabase, $incrementalDatabase] as $database) {
        if (preg_match('/^antares_e0041_(fresh|incremental)_[a-f0-9]{10}$/', $database) === 1) {
            $server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        }
    }
}
