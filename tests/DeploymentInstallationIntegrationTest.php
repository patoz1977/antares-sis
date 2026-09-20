<?php

declare(strict_types=1);

use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\MigrationRunner;
use Tests\Support\DeploymentInstallSql;
use Tests\Support\DeploymentSchemaInspector;

require dirname(__DIR__) . '/vendor/autoload.php';

function deploymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, string> */
function deploymentEnvironment(): array
{
    $environment = [];
    foreach ([
        'E0041_DB_HOST',
        'E0041_DB_PORT',
        'E0041_DB_USERNAME',
        'E0041_DB_PREFIX',
        'DEPLOY001_INSTALL_SQL_PATH',
        'DEPLOY001_ADMIN_PASSWORD',
        'DEPLOY001_RUNTIME_ROOT',
    ] as $name) {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('%s must be explicitly defined and non-empty.', $name));
        }

        $environment[$name] = $value;
    }

    $password = getenv('E0041_DB_PASSWORD');
    if ($password === false) {
        throw new RuntimeException('E0041_DB_PASSWORD must be explicitly defined; an empty value is allowed.');
    }
    $environment['E0041_DB_PASSWORD'] = $password;

    if (getenv('E0041_DB_ALLOW_DISPOSABLE') !== '1') {
        throw new RuntimeException('E0041_DB_ALLOW_DISPOSABLE=1 is required for the rehearsal.');
    }

    foreach (['E0041_ADMIN_PERSON_ID', 'E0041_ADMIN_INITIAL_PASSWORD'] as $name) {
        deploymentAssert(
            getenv($name) === false,
            sprintf('%s must be unset so Base A contains only global reference seed data.', $name),
        );
    }

    deploymentAssert(
        filter_var($environment['E0041_DB_PORT'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]) !== false,
        'E0041_DB_PORT must be a valid TCP port.',
    );
    deploymentAssert(
        preg_match('/^[a-z][a-z0-9_]{2,30}$/', $environment['E0041_DB_PREFIX']) === 1,
        'E0041_DB_PREFIX must be a safe lowercase disposable prefix.',
    );

    foreach ([
        $environment['E0041_DB_HOST'],
        $environment['E0041_DB_USERNAME'],
        $environment['E0041_DB_PASSWORD'],
        $environment['E0041_DB_PREFIX'],
    ] as $value) {
        deploymentAssert(
            !str_contains(strtolower($value), 'ueant'),
            'UEAnt is forbidden in every deployment rehearsal database value.',
        );
    }

    return $environment;
}

/** @param array<string, string> $environment */
function deploymentDatabaseManager(array $environment, string $database): ConnectionManager
{
    return new ConnectionManager(new ConnectionFactory(), new DatabaseConfig([
        'driver' => 'mysql',
        'host' => $environment['E0041_DB_HOST'],
        'port' => (int) $environment['E0041_DB_PORT'],
        'database' => $database,
        'username' => $environment['E0041_DB_USERNAME'],
        'password' => $environment['E0041_DB_PASSWORD'],
        'charset' => 'utf8mb4',
    ]));
}

/** @param list<string> $databases */
function deploymentDropDatabases(PDO $server, array $databases): array
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
function deploymentExpectedMigrations(): array
{
    $files = scandir(dirname(__DIR__) . '/database/migrations');
    deploymentAssert(is_array($files), 'Unable to read the migration directory.');

    $migrations = [];
    foreach ($files as $file) {
        if (preg_match('/^(\d+_.+)\.php$/', $file, $matches) === 1) {
            $migrations[] = $matches[1];
        }
    }
    sort($migrations, SORT_STRING);

    return $migrations;
}

/** @return list<array<string, mixed>> */
function deploymentRows(PDO $connection, string $sql): array
{
    return $connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function deploymentValidateMigrationLedger(PDO $connection, array $expected): void
{
    $actual = $connection->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    deploymentAssert(
        $actual === $expected,
        sprintf(
            'Migration ledger differs. Expected: %s. Actual: %s.',
            implode(', ', $expected),
            implode(', ', array_map('strval', $actual)),
        ),
    );
}

function deploymentValidateSystemStatuses(PDO $migrationConnection, PDO $installConnection): void
{
    $typesSql = 'SELECT code, name, description, is_active FROM status_types ORDER BY code';
    $statusesSql = 'SELECT st.code AS status_type_code, s.code, s.name, s.description, '
        . 's.sort_order, s.is_active FROM statuses s INNER JOIN status_types st '
        . 'ON st.id = s.status_type_id ORDER BY st.code, s.sort_order, s.code';

    deploymentAssert(
        deploymentRows($installConnection, $typesSql) === deploymentRows($migrationConnection, $typesSql),
        'Installed status_types differ from the approved StatusTypeSeeder baseline.',
    );
    deploymentAssert(
        deploymentRows($installConnection, $statusesSql) === deploymentRows($migrationConnection, $statusesSql),
        'Installed statuses differ from the approved StatusSeeder baseline.',
    );
}

function deploymentValidateCatalogBootstrap(PDO $connection): void
{
    foreach (['document_types', 'sexes', 'relationship_types'] as $table) {
        $row = $connection->query(sprintf(
            "SELECT COUNT(*) AS total, SUM(is_active = 1) AS active_count, "
            . "SUM(NULLIF(TRIM(code), '') IS NULL OR NULLIF(TRIM(name), '') IS NULL "
            . "OR is_active NOT IN (0, 1)) AS invalid_count FROM `%s`",
            $table,
        ))->fetch(PDO::FETCH_ASSOC);
        deploymentAssert(
            $row !== false
            && (int) $row['total'] > 0
            && (int) $row['active_count'] > 0
            && (int) $row['invalid_count'] === 0,
            sprintf('%s does not contain a valid active institutional baseline.', $table),
        );
    }

    foreach (['grades', 'sections'] as $table) {
        $row = $connection->query(sprintf(
            "SELECT COUNT(*) AS total, SUM(st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE') AS active_count, "
            . "SUM(st.code <> 'GENERAL_STATUS' OR s.code NOT IN ('ACTIVE', 'INACTIVE')) AS invalid_count "
            . "FROM `%s` c INNER JOIN statuses s ON s.id = c.status_id "
            . 'INNER JOIN status_types st ON st.id = s.status_type_id',
            $table,
        ))->fetch(PDO::FETCH_ASSOC);
        deploymentAssert(
            $row !== false
            && (int) $row['total'] > 0
            && (int) $row['active_count'] > 0
            && (int) $row['invalid_count'] === 0,
            sprintf('%s does not contain a valid GENERAL_STATUS institutional baseline.', $table),
        );
    }

    $periods = $connection->query(
        "SELECT COUNT(*) AS total, SUM(st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE') AS active_count, "
        . "SUM(st.code <> 'GENERAL_STATUS' OR s.code NOT IN ('ACTIVE', 'INACTIVE')) AS invalid_count "
        . 'FROM academic_periods ap INNER JOIN statuses s ON s.id = ap.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id'
    )->fetch(PDO::FETCH_ASSOC);
    deploymentAssert(
        $periods !== false
        && (int) $periods['total'] > 0
        && (int) $periods['active_count'] === 1
        && (int) $periods['invalid_count'] === 0,
        'The clean install must contain exactly one initial ACTIVE AcademicPeriod.',
    );
}

function deploymentValidateAdministrator(PDO $connection, string $password): void
{
    deploymentAssert(
        (int) $connection->query('SELECT COUNT(*) FROM persons')->fetchColumn() === 1,
        'The clean install must contain exactly one administrator Person.',
    );
    deploymentAssert(
        (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1,
        'The clean install must contain exactly one administrator User.',
    );

    $administrator = $connection->query(
        "SELECT u.login_identifier, u.normalized_login_identifier, u.password_hash, "
        . "u.failed_login_attempts, u.locked_at, ust.code AS user_status_code, "
        . "ustt.code AS user_status_type_code, pst.code AS person_status_code, "
        . "pstt.code AS person_status_type_code, sx.is_active AS sex_is_active "
        . 'FROM users u INNER JOIN persons p ON p.id = u.person_id '
        . 'INNER JOIN statuses ust ON ust.id = u.status_id '
        . 'INNER JOIN status_types ustt ON ustt.id = ust.status_type_id '
        . 'INNER JOIN statuses pst ON pst.id = p.status_id '
        . 'INNER JOIN status_types pstt ON pstt.id = pst.status_type_id '
        . 'INNER JOIN sexes sx ON sx.id = p.sex_id'
    )->fetch(PDO::FETCH_ASSOC);
    deploymentAssert($administrator !== false, 'The administrator User does not reference a valid Person.');
    deploymentAssert(
        $administrator['login_identifier'] === 'admin'
        && $administrator['normalized_login_identifier'] === 'admin'
        && $administrator['user_status_type_code'] === 'USER_STATUS'
        && $administrator['user_status_code'] === 'ACTIVE'
        && $administrator['person_status_type_code'] === 'GENERAL_STATUS'
        && $administrator['person_status_code'] === 'ACTIVE'
        && (int) $administrator['sex_is_active'] === 1
        && (int) $administrator['failed_login_attempts'] === 0
        && $administrator['locked_at'] === null,
        'The initial administrator identity or status baseline is invalid.',
    );

    $passwordHash = (string) $administrator['password_hash'];
    deploymentAssert(password_verify($password, $passwordHash), 'The rehearsal administrator password does not verify.');
    deploymentAssert(
        (password_get_info($passwordHash)['algoName'] ?? 'unknown') !== 'unknown',
        'The administrator password_hash is not recognized by PHP password APIs.',
    );
}

function deploymentValidateEmptyData(PDO $connection): void
{
    foreach ([
        'marital_statuses',
        'education_levels',
        'provinces',
        'cantons',
        'parishes',
        'representatives',
        'students',
        'families',
        'family_representatives',
        'family_students',
        'family_addresses',
        'representative_address_assignments',
        'student_address_assignments',
        'family_emergency_contacts',
        'emergency_contact_assignments',
        'family_authorized_pickups',
        'authorized_pickup_assignments',
        'acknowledgement_requirements',
        'representative_acknowledgement_completions',
        'representative_acknowledgements',
        'enrollments',
    ] as $table) {
        deploymentAssert(
            (int) $connection->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn() === 0,
            sprintf('The clean install contains unauthorized rows in %s.', $table),
        );
    }
}

/** @param array<string, string> $values */
function deploymentWriteEnvironment(string $path, array $values): void
{
    $lines = [];
    foreach ($values as $name => $value) {
        deploymentAssert(
            preg_match('/^[A-Z][A-Z0-9_]*$/', $name) === 1
            && !str_contains($value, "\r")
            && !str_contains($value, "\n"),
            'Unsafe rehearsal environment value.',
        );
        $lines[] = sprintf('%s="%s"', $name, addcslashes($value, "\\\""));
    }

    deploymentAssert(
        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false,
        'Unable to create the temporary rehearsal .env.',
    );
}

/** @return array{status: int, body: string, headers: list<string>} */
function deploymentHttpRequest(
    string $url,
    string $method,
    array $form,
    array &$cookies,
): array {
    $headers = ['Connection: close'];
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $options = [
        'method' => $method,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 15,
        'header' => implode("\r\n", $headers),
    ];
    if ($method === 'POST') {
        $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['content'] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
    }

    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    $responseHeaders = $http_response_header ?? [];
    deploymentAssert(is_string($body) && $responseHeaders !== [], 'The rehearsal HTTP request failed.');
    deploymentAssert(
        preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $statusMatch) === 1,
        'The rehearsal HTTP response status is invalid.',
    );

    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $header, $cookieMatch) === 1) {
            $cookies[$cookieMatch[1]] = $cookieMatch[2];
        }
    }

    return ['status' => (int) $statusMatch[1], 'body' => $body, 'headers' => $responseHeaders];
}

function deploymentHeader(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return null;
}

function deploymentWaitForServer(int $port): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);

            return;
        }
        usleep(100000);
    }

    throw new RuntimeException('The temporary PHP HTTP server did not become ready.');
}

function deploymentFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    deploymentAssert(is_resource($server), 'Unable to reserve a local rehearsal port.');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    deploymentAssert(is_string($name) && str_contains($name, ':'), 'Unable to resolve the rehearsal port.');

    return (int) substr(strrchr($name, ':'), 1);
}

function deploymentRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    deploymentAssert(is_array($items), 'Unable to inspect a temporary rehearsal directory.');
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deploymentRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

/** @param array<string, string> $environment */
function deploymentRunHttpRehearsal(array $environment, string $database): void
{
    $repositoryRoot = dirname(__DIR__);
    $runtimeRoot = realpath($environment['DEPLOY001_RUNTIME_ROOT']);
    deploymentAssert(
        $runtimeRoot !== false && is_dir($runtimeRoot),
        'DEPLOY001_RUNTIME_ROOT must identify the prepared local production runtime.',
    );
    deploymentAssert(
        !DeploymentInstallSql::isInsideRoot($runtimeRoot, $repositoryRoot),
        'The rehearsal runtime must remain outside the repository.',
    );
    foreach ([
        'app',
        'bin',
        'bootstrap',
        'composer.json',
        'composer.lock',
        'config',
        'core',
        'database',
        'public',
        'resources',
        'routes',
        'storage',
        'vendor',
    ] as $entry) {
        deploymentAssert(
            file_exists($runtimeRoot . DIRECTORY_SEPARATOR . $entry),
            sprintf('The prepared runtime is missing %s.', $entry),
        );
    }
    deploymentAssert(
        is_writable($runtimeRoot . DIRECTORY_SEPARATOR . 'storage'),
        'The prepared runtime storage directory must be writable.',
    );
    foreach (['.git', '.ai', 'tests'] as $excluded) {
        deploymentAssert(
            !file_exists($runtimeRoot . DIRECTORY_SEPARATOR . $excluded),
            sprintf('The prepared runtime must not contain %s.', $excluded),
        );
    }

    $envPath = $runtimeRoot . DIRECTORY_SEPARATOR . '.env';
    deploymentAssert(!file_exists($envPath), 'The prepared rehearsal runtime already contains a .env file.');

    $institutionDirectory = $runtimeRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'institution';
    $createdInstitutionDirectory = false;
    if (!is_dir($institutionDirectory)) {
        deploymentAssert(
            mkdir($institutionDirectory, 0700, true),
            'Unable to create the temporary institution asset directory.',
        );
        $createdInstitutionDirectory = true;
    }
    $logoPath = $institutionDirectory . DIRECTORY_SEPARATOR . 'deploy-001-logo.png';
    $faviconPath = $institutionDirectory . DIRECTORY_SEPARATOR . 'deploy-001-favicon.ico';
    deploymentAssert(!file_exists($logoPath) && !file_exists($faviconPath), 'Temporary branding targets already exist.');

    $runtimeDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-deploy-runtime-' . bin2hex(random_bytes(8));
    deploymentAssert(mkdir($runtimeDirectory, 0700, true), 'Unable to create the rehearsal runtime directory.');
    $serverLog = $runtimeDirectory . DIRECTORY_SEPARATOR . 'server.log';
    $sessionDirectory = $runtimeDirectory . DIRECTORY_SEPARATOR . 'sessions';
    deploymentAssert(mkdir($sessionDirectory, 0700, true), 'Unable to create the rehearsal session directory.');
    $routerPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.deploy-001-router.php';
    deploymentAssert(!file_exists($routerPath), 'The temporary PHP server router target already exists.');

    $process = null;
    try {
        file_put_contents($logoPath, 'DEPLOY-001 temporary logo');
        file_put_contents($faviconPath, 'DEPLOY-001 temporary favicon');
        deploymentAssert(
            file_put_contents($routerPath, <<<'PHP'
<?php

$publicRoot = realpath(__DIR__);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath === '/.deploy-001-session-path') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo session_save_path();

    return;
}
if ($publicRoot !== false && is_string($requestPath)) {
    $candidate = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));
    $insidePublicRoot = $candidate !== false
        && ($candidate === $publicRoot || str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR));
    if ($insidePublicRoot && is_file($candidate) && $candidate !== __FILE__) {
        return false;
    }
}

require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
PHP
            ) !== false,
            'Unable to create the temporary PHP server router.',
        );
        deploymentWriteEnvironment($envPath, [
            'APP_NAME' => 'DEPLOY-001 Rehearsal',
            'APP_LOGO_PATH' => '/institution/deploy-001-logo.png',
            'APP_FAVICON_PATH' => '/institution/deploy-001-favicon.ico',
            'APP_PRIMARY_COLOR' => '#164E63',
            'APP_ASSET_VERSION' => 'deploy-001-phase2',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_TIMEZONE' => 'UTC',
            'APP_LOCALE' => 'es',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $environment['E0041_DB_HOST'],
            'DB_PORT' => $environment['E0041_DB_PORT'],
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $environment['E0041_DB_USERNAME'],
            'DB_PASSWORD' => $environment['E0041_DB_PASSWORD'],
            'DB_CHARSET' => 'utf8mb4',
            'AUTH_MAX_FAILED_ATTEMPTS' => '5',
            'AUTH_LOCKOUT_DURATION_SECONDS' => '900',
        ]);

        $port = deploymentFreePort();
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'session.save_path=' . chr(34) . $sessionDirectory . chr(34),
                '-S',
                '127.0.0.1:' . $port,
                '-t',
                $runtimeRoot . DIRECTORY_SEPARATOR . 'public',
                $routerPath,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $serverLog, 'a'],
                2 => ['file', $serverLog, 'a'],
            ],
            $pipes,
            $runtimeRoot,
        );
        deploymentAssert(is_resource($process), 'Unable to start the temporary PHP HTTP server.');
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        deploymentWaitForServer($port);

        $cookies = [];
        $baseUrl = 'http://127.0.0.1:' . $port;
        $sessionPath = deploymentHttpRequest($baseUrl . '/.deploy-001-session-path', 'GET', [], $cookies);
        deploymentAssert(
            $sessionPath['status'] === 200 && $sessionPath['body'] === $sessionDirectory,
            sprintf(
                'The temporary PHP server did not use its isolated session path. Expected: %s. Actual: %s.',
                $sessionDirectory,
                $sessionPath['body'],
            ),
        );
        $login = deploymentHttpRequest($baseUrl . '/login', 'GET', [], $cookies);
        deploymentAssert($login['status'] === 200, 'GET /login did not return HTTP 200.');
        deploymentAssert(
            str_contains($login['body'], 'DEPLOY-001 Rehearsal')
            && str_contains($login['body'], '--app-primary: #164E63')
            && str_contains($login['body'], 'src="/institution/deploy-001-logo.png"')
            && str_contains($login['body'], 'href="/institution/deploy-001-favicon.ico"'),
            'The login page did not render the temporary White Label configuration.',
        );
        deploymentAssert(
            preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $login['body'], $csrfMatch) === 1,
            'The login page did not expose a valid CSRF token.',
        );

        $logo = deploymentHttpRequest($baseUrl . '/institution/deploy-001-logo.png', 'GET', [], $cookies);
        $favicon = deploymentHttpRequest($baseUrl . '/institution/deploy-001-favicon.ico', 'GET', [], $cookies);
        deploymentAssert(
            $logo['status'] === 200 && $logo['body'] === 'DEPLOY-001 temporary logo'
            && $favicon['status'] === 200 && $favicon['body'] === 'DEPLOY-001 temporary favicon',
            'Temporary White Label assets were not served from /institution.',
        );

        $authentication = deploymentHttpRequest($baseUrl . '/login', 'POST', [
            '_csrf_token' => $csrfMatch[1],
            'username' => 'admin',
            'password' => $environment['DEPLOY001_ADMIN_PASSWORD'],
        ], $cookies);
        $authenticationFailure = '(none)';
        if (
            $authentication['status'] !== 303
            || deploymentHeader($authentication['headers'], 'Location') !== '/'
        ) {
            $failedLogin = deploymentHttpRequest($baseUrl . '/login', 'GET', [], $cookies);
            $sessionFiles = glob($sessionDirectory . DIRECTORY_SEPARATOR . 'sess_*');
            $authenticationFailure = sprintf(
                '%s; follow-up status=%d location=%s body-bytes=%d; cookies=%s; session-files=%d',
                match (true) {
                    str_contains($failedLogin['body'], 'Solicitud') => 'csrf',
                    str_contains($failedLogin['body'], 'Credenciales inválidas.') => 'credentials',
                    default => 'unclassified',
                },
                $failedLogin['status'],
                deploymentHeader($failedLogin['headers'], 'Location') ?? '(none)',
                strlen($failedLogin['body']),
                $cookies === [] ? '(none)' : implode(',', array_keys($cookies)),
                is_array($sessionFiles) ? count($sessionFiles) : 0,
            );
        }
        deploymentAssert(
            $authentication['status'] === 303
            && deploymentHeader($authentication['headers'], 'Location') === '/',
            sprintf(
                'The real admin authentication flow did not return the expected redirect. Status: %d. Location: %s. Failure: %s.',
                $authentication['status'],
                deploymentHeader($authentication['headers'], 'Location') ?? '(none)',
                $authenticationFailure,
            ),
        );

        $dashboard = deploymentHttpRequest($baseUrl . '/', 'GET', [], $cookies);
        deploymentAssert(
            $dashboard['status'] === 200
            && str_contains($dashboard['body'], 'Panel administrativo')
            && str_contains($dashboard['body'], 'href="/persons"')
            && str_contains($dashboard['body'], 'DEPLOY-001 Rehearsal'),
            'The authenticated admin did not reach the expected administrative Dashboard.',
        );

        $migrate = proc_open(
            [PHP_BINARY, 'bin/migrate'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $runtimeRoot,
        );
        deploymentAssert(is_resource($migrate), 'Unable to run the local post-import migration check.');
        fclose($migratePipes[0]);
        $migrateOutput = stream_get_contents($migratePipes[1]);
        $migrateError = stream_get_contents($migratePipes[2]);
        fclose($migratePipes[1]);
        fclose($migratePipes[2]);
        $migrateExit = proc_close($migrate);
        deploymentAssert(
            $migrateExit === 0
            && is_string($migrateOutput)
            && str_contains($migrateOutput, 'No pending migrations.')
            && !str_contains($migrateOutput, 'Migration executed:')
            && trim((string) $migrateError) === '',
            'The local post-import migration check did not confirm a complete ledger.',
        );
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ([$envPath, $logoPath, $faviconPath, $routerPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if ($createdInstitutionDirectory && is_dir($institutionDirectory)) {
            rmdir($institutionDirectory);
        }
        deploymentRemoveDirectory($runtimeDirectory);
    }
}

$environment = deploymentEnvironment();
$repositoryRoot = dirname(__DIR__);
$artifact = DeploymentInstallSql::load(
    $environment['DEPLOY001_INSTALL_SQL_PATH'],
    $repositoryRoot,
    $environment['DEPLOY001_ADMIN_PASSWORD'],
);

$prefix = $environment['E0041_DB_PREFIX'];
$suffix = bin2hex(random_bytes(5));
$migrationDatabase = $prefix . '_migration_' . $suffix;
$installDatabase = $prefix . '_install_' . $suffix;
foreach ([$migrationDatabase, $installDatabase] as $database) {
    deploymentAssert(
        preg_match('/^[a-z][a-z0-9_]+$/', $database) === 1 && strlen($database) <= 64,
        'Unsafe disposable deployment rehearsal database name.',
    );
}

echo sprintf(
    "DEPLOY-001 disposable target: host=%s port=%d prefix=%s\n",
    $environment['E0041_DB_HOST'],
    (int) $environment['E0041_DB_PORT'],
    $prefix,
);
echo sprintf("install.sql: size=%d sha256=%s tracked=NO\n", $artifact->size, $artifact->sha256);

$server = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;charset=utf8mb4',
        $environment['E0041_DB_HOST'],
        (int) $environment['E0041_DB_PORT'],
    ),
    $environment['E0041_DB_USERNAME'],
    $environment['E0041_DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);

$createdDatabases = [];
try {
    foreach ([$migrationDatabase, $installDatabase] as $database) {
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database,
        ));
        $createdDatabases[] = $database;
    }

    $migrationManager = deploymentDatabaseManager($environment, $migrationDatabase);
    ob_start();
    (new MigrationRunner($migrationManager))->run();
    ob_end_clean();
    $migrationConnection = $migrationManager->connection();

    $installConnection = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $environment['E0041_DB_HOST'],
            (int) $environment['E0041_DB_PORT'],
            $installDatabase,
        ),
        $environment['E0041_DB_USERNAME'],
        $environment['E0041_DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ],
    );
    $installConnection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $installConnection->exec("SET time_zone = '+00:00'");
    try {
        $installConnection->exec($artifact->contents);
    } catch (Throwable $exception) {
        throw new RuntimeException('The external install.sql import failed.', previous: $exception);
    }

    $version = (string) $installConnection->query('SELECT VERSION()')->fetchColumn();
    deploymentAssert(
        str_contains(strtolower($version), 'mariadb') && preg_match('/^10\.4\./', $version) === 1,
        'DEPLOY-001 Phase 2 requires the approved MariaDB 10.4 physical target.',
    );

    $inspector = new DeploymentSchemaInspector();
    $migrationSchema = $inspector->snapshot($migrationConnection);
    $installSchema = $inspector->snapshot($installConnection);
    deploymentAssert(
        $migrationSchema === $installSchema,
        "Migrations/install.sql schema parity failed:\n" . $inspector->difference($migrationSchema, $installSchema),
    );

    $expectedMigrations = deploymentExpectedMigrations();
    deploymentAssert(count($expectedMigrations) === 11, 'The repository no longer exposes the expected 11-migration baseline.');
    deploymentValidateMigrationLedger($migrationConnection, $expectedMigrations);
    deploymentValidateMigrationLedger($installConnection, $expectedMigrations);
    deploymentValidateSystemStatuses($migrationConnection, $installConnection);
    deploymentValidateCatalogBootstrap($installConnection);
    deploymentValidateAdministrator($installConnection, $environment['DEPLOY001_ADMIN_PASSWORD']);
    deploymentValidateEmptyData($installConnection);
    deploymentRunHttpRehearsal($environment, $installDatabase);
    deploymentValidateAdministrator($installConnection, $environment['DEPLOY001_ADMIN_PASSWORD']);
    deploymentValidateEmptyData($installConnection);

    $tableCount = count($installSchema['tables']);
    $foreignKeyCount = count($installSchema['foreign_keys']);
    echo 'MariaDB version: ' . $version . "\n";
    echo sprintf("Physical inventory: %d tables including migrations metadata; %d foreign keys\n", $tableCount, $foreignKeyCount);
    echo "PASS migrations 001-011 fresh baseline\n";
    echo "PASS install.sql fresh autonomous import\n";
    echo "PASS migrations/install.sql physical schema parity\n";
    echo "PASS migration ledger and approved system statuses\n";
    echo "PASS institutional catalog bootstrap and approved empty catalogs\n";
    echo "PASS initial admin Person/User and PHP password hash\n";
    echo "PASS real admin authentication and administrative Dashboard access\n";
    echo "PASS White Label runtime name color logo and favicon\n";
    echo "PASS Bulk Import and Enrollment catalog readiness\n";
    echo "PASS no demo Family Representative Student Enrollment or acknowledgement data\n";
    echo "PASS local post-import migration check reports no pending migrations\n";
} finally {
    $cleanupFailures = deploymentDropDatabases($server, $createdDatabases);
    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            'Deployment rehearsal database cleanup failed: ' . implode('; ', $cleanupFailures),
        );
    }

    $residual = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE LOCATE(:prefix, schema_name) = 1',
    );
    $residual->execute([':prefix' => $prefix]);
    deploymentAssert(
        (int) $residual->fetchColumn() === 0,
        'Deployment rehearsal left a disposable database behind.',
    );
    echo "PASS deployment rehearsal disposable database cleanup; residual disposable databases = 0\n";
}
