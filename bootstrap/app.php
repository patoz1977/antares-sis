<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use Core\Container\Container;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Foundation\Application;
use Core\Middleware\AuthenticationMiddleware;
use Core\Security\AuthenticatedUserProviderInterface;
use Core\Session\Session;
use Core\Session\SessionInterface;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Contract\TransactionManager;
use App\IdentityAccess\Domain\UserRepository as IdentityUserRepository;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Infrastructure\Logging\ErrorLogSecurityEventLogger;
use App\IdentityAccess\Infrastructure\Persistence\PdoTransactionManager;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\IdentityAccess\Infrastructure\Session\PhpSessionManager;
use App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager;
use App\IdentityAccess\Infrastructure\Time\SystemClock;
use App\Person\Application\CreatePerson;
use App\Person\Application\GetPerson;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\PersonRepository;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use App\Person\Http\PersonFormOptionsProvider;
use App\Person\Infrastructure\Persistence\PdoPersonFormOptionsProvider;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;

use App\Services\AuthenticationService;
use App\Services\AuthenticationServiceInterface;

// Load .env file from project root
$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';

if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // support "export KEY=VAL"
        if (str_starts_with(strtolower($line), 'export ')) {
            $line = preg_replace('/^export\s+/i', '', $line, 1);
        }

        $pos = strpos($line, '=');

        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = substr($line, $pos + 1);

        // remove surrounding whitespace
        $value = trim($value);

        // strip comments for unquoted values
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $parts = preg_split('/\s+#/', $value, 2);
            $value = $parts[0];
        }

        // remove surrounding quotes and unescape
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = str_replace("\\'", "'", substr($value, 1, -1));
        }

        // set into environments
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$config = require $root . '/config/app.php';

$timezone = (string) ($config['timezone'] ?? 'UTC');
$locale = (string) ($config['locale'] ?? 'en_US.UTF-8');

date_default_timezone_set($timezone);

if (!setlocale(LC_ALL, $locale)) {
    setlocale(LC_ALL, 'C');
}

$databaseConfigValues = require $root . '/config/database.php';

$databaseConfigValues['username'] = (string) ($databaseConfigValues['username'] ?? '');
$databaseConfigValues['password'] = (string) ($databaseConfigValues['password'] ?? '');
$databaseConfigValues['database'] = (string) ($databaseConfigValues['database'] ?? '');
$databaseConfigValues['host'] = (string) ($databaseConfigValues['host'] ?? '');
$databaseConfigValues['charset'] = (string) ($databaseConfigValues['charset'] ?? 'utf8mb4');

$databaseConfig = new DatabaseConfig($databaseConfigValues);

$container = new Container();
$container->instance(DatabaseConfig::class, $databaseConfig);
$container->singleton(ConnectionFactory::class, ConnectionFactory::class);
$container->singleton(ConnectionManager::class, ConnectionManager::class);
$container->singleton(Session::class, Session::class);
$container->singleton(SessionInterface::class, Session::class);
$container->singleton(AuthenticationService::class, AuthenticationService::class);
$container->singleton(AuthenticationServiceInterface::class, AuthenticationService::class);
$container->singleton(SessionManager::class, PhpSessionManager::class);
$container->singleton(CsrfTokenManager::class, SessionCsrfTokenManager::class);
$container->singleton(Clock::class, SystemClock::class);
$container->singleton(PasswordHasher::class, NativePasswordHasher::class);
$container->singleton(SecurityEventLogger::class, ErrorLogSecurityEventLogger::class);
$container->singleton(TransactionManager::class, PdoTransactionManager::class);
$container->singleton(IdentityUserRepository::class, PdoUserRepository::class);
$maximumFailedAttempts = filter_var(
    $config['auth_max_failed_attempts'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$lockoutDurationSeconds = filter_var(
    $config['auth_lockout_duration_seconds'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$container->instance(
    AuthenticationPolicy::class,
    new AuthenticationPolicy(
        is_int($maximumFailedAttempts) ? $maximumFailedAttempts : 0,
        is_int($lockoutDurationSeconds) ? $lockoutDurationSeconds : 0,
    )
);
$container->singleton(AuthenticatedUserProviderInterface::class, AuthenticationService::class);
$container->singleton(AuthenticationController::class, AuthenticationController::class);
$container->singleton(AuthenticationMiddleware::class, AuthenticationMiddleware::class);
$container->singleton(PersonRepository::class, PdoPersonRepository::class);
$container->singleton(CreatePerson::class, CreatePerson::class);
$container->singleton(GetPerson::class, GetPerson::class);
$container->singleton(UpdatePerson::class, UpdatePerson::class);
$container->singleton(PersonFormOptionsProvider::class, PdoPersonFormOptionsProvider::class);
$container->singleton(PersonController::class, PersonController::class);
$container->singleton(PersonAdministrationMiddleware::class, PersonAdministrationMiddleware::class);

$app = new Application($config, $container);

$app->kernel()->setMiddlewareResolver(
    static fn (string $middleware): object => $container->make($middleware)
);

return $app;
