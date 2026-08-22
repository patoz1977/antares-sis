<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use DateTimeImmutable;
use DateTimeZone;

final class RepresentativeEnrollmentInputMapper
{
    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     * @param list<string> $errors
     * @return array<string, string>
     */
    public function scalarValues(array $input, array $allowed, array &$errors): array
    {
        $values = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if (!is_scalar($input[$key])) {
                $errors[] = $this->label($key) . ' must be a single value.';
                continue;
            }
            $values[$key] = trim((string) $input[$key]);
        }

        return $values;
    }

    /** @param list<string> $errors */
    public function requiredString(array $values, string $key, array &$errors): string
    {
        $value = $values[$key] ?? '';
        if ($value === '') {
            $errors[] = $this->label($key) . ' is required.';
        }

        return $value;
    }

    public function optionalString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? '';

        return $value === '' ? null : $value;
    }

    /** @param list<string> $errors */
    public function positiveInteger(array $values, string $key, array &$errors): ?int
    {
        $value = $values[$key] ?? null;
        $integer = $this->parsePositiveInteger($value);
        if ($integer === null) {
            $errors[] = $this->label($key) . ' must be a positive integer.';
        }

        return $integer;
    }

    /** @param list<string> $errors */
    public function optionalPositiveInteger(array $values, string $key, array &$errors): ?int
    {
        if (($values[$key] ?? '') === '') {
            return null;
        }

        return $this->positiveInteger($values, $key, $errors);
    }

    public function parsePositiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }

    /** @param list<string> $errors */
    public function boolean(array $values, string $key, array &$errors): ?bool
    {
        $value = $values[$key] ?? null;
        if ($value === '1') {
            return true;
        }
        if ($value === '0') {
            return false;
        }
        $errors[] = $this->label($key) . ' must be answered Yes or No.';

        return null;
    }

    /** @param list<string> $errors */
    public function date(array $values, string $key, array &$errors): ?DateTimeImmutable
    {
        $value = $values[$key] ?? '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            $errors[] = $this->label($key) . ' must use the YYYY-MM-DD format.';

            return null;
        }

        return $date;
    }

    private function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
