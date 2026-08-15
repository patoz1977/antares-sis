<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\Support;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;

final class EnrollmentText
{
    public static function required(string $value, int $maximum, string $label): string
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > $maximum) {
            throw new InvalidEnrollmentState(sprintf(
                '%s must contain between 1 and %d characters.',
                $label,
                $maximum,
            ));
        }

        return $normalized;
    }

    public static function optional(?string $value, ?int $maximum, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if ($maximum !== null && mb_strlen($normalized, 'UTF-8') > $maximum) {
            throw new InvalidEnrollmentState(sprintf('%s cannot exceed %d characters.', $label, $maximum));
        }

        return $normalized;
    }

    public static function email(?string $value, string $label, bool $required): ?string
    {
        $normalized = $required
            ? self::required($value ?? '', 254, $label)
            : self::optional($value, 254, $label);

        if ($normalized !== null && filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidEnrollmentState(sprintf('%s must be a valid email address.', $label));
        }

        return $normalized;
    }

    private function __construct()
    {
    }
}
