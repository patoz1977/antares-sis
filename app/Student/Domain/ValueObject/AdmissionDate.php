<?php

declare(strict_types=1);

namespace App\Student\Domain\ValueObject;

use App\Student\Domain\Exception\InvalidStudentState;
use DateTimeImmutable;

final readonly class AdmissionDate
{
    private DateTimeImmutable $value;

    public function __construct(DateTimeImmutable $value, DateTimeImmutable $today)
    {
        if ($value->format('Y-m-d') > $today->format('Y-m-d')) {
            throw new InvalidStudentState('Admission date cannot be in the future.');
        }

        $this->value = $value->setTime(0, 0);
    }

    public function value(): DateTimeImmutable
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->format('Y-m-d') === $other->value->format('Y-m-d');
    }
}
