<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain\ValueObject;

use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;
use DateTimeImmutable;
use DateTimeZone;

final readonly class AcademicPeriodDateRange
{
    private DateTimeImmutable $startsOn;
    private DateTimeImmutable $endsOn;

    public function __construct(DateTimeImmutable $startsOn, DateTimeImmutable $endsOn)
    {
        $timezone = new DateTimeZone('UTC');
        $normalizedStart = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn->format('Y-m-d'), $timezone);
        $normalizedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn->format('Y-m-d'), $timezone);
        if ($normalizedStart === false || $normalizedEnd === false) {
            throw new InvalidAcademicPeriodState('AcademicPeriod dates are invalid.');
        }
        $this->startsOn = $normalizedStart;
        $this->endsOn = $normalizedEnd;

        if ($this->endsOn < $this->startsOn) {
            throw new InvalidAcademicPeriodState('AcademicPeriod end date cannot precede its start date.');
        }
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function endsOn(): DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function equals(self $other): bool
    {
        return $this->startsOn == $other->startsOn && $this->endsOn == $other->endsOn;
    }
}
