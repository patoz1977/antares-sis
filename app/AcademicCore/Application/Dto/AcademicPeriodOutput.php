<?php

declare(strict_types=1);

namespace App\AcademicCore\Application\Dto;

use App\AcademicCore\Application\Exception\InvalidPersistedAcademicPeriodResult;
use App\AcademicCore\Domain\AcademicPeriod;

final readonly class AcademicPeriodOutput
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $startsOn,
        public string $endsOn,
        public string $status,
    ) {
    }

    public static function fromAcademicPeriod(AcademicPeriod $period): self
    {
        $id = $period->id();
        if ($id === null) {
            throw new InvalidPersistedAcademicPeriodResult(
                'AcademicPeriod does not have a persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $period->code()->value(),
            $period->name()->value(),
            $period->dates()->startsOn()->format('Y-m-d'),
            $period->dates()->endsOn()->format('Y-m-d'),
            $period->status()->value,
        );
    }
}
