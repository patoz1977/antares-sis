<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use Core\Container\Container;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Foundation\Application;
use Core\Session\Session;
use Core\Session\SessionInterface;

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

$app = new Application($config, $container);

return $app;
