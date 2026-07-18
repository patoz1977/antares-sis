<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

$config = require dirname(__DIR__) . '/config/app.php';

$timezone = (string) ($config['timezone'] ?? 'UTC');
$locale = (string) ($config['locale'] ?? 'en_US.UTF-8');

date_default_timezone_set($timezone);

if (!setlocale(LC_ALL, $locale)) {
    setlocale(LC_ALL, 'C');
}

return $config;
