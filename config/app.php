<?php

declare(strict_types=1);

return [
    'app_name' => env('APP_NAME', 'Antares SIS'),
    'environment' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => env('APP_LOCALE', 'en_US.UTF-8'),
    'auth_max_failed_attempts' => env('AUTH_MAX_FAILED_ATTEMPTS', 5),
    'auth_lockout_duration_seconds' => env('AUTH_LOCKOUT_DURATION_SECONDS', 900),
];
