<?php

declare(strict_types=1);

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    if (is_string($value)) {
        $normalizedValue = strtolower($value);

        if (in_array($normalizedValue, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($normalizedValue, ['false', '0', 'off', 'no', 'null', 'none'], true)) {
            return false;
        }
    }

    return $value;
}
