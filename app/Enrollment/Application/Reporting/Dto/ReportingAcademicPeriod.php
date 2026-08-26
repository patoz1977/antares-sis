<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use RuntimeException;

final readonly class ReportingAcademicPeriod
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $startsOn,
        public string $endsOn,
        public AcademicPeriodStatus $status,
    ) {
        if ($id <= 0) {
            throw new RuntimeException('Reporting AcademicPeriod requires a persisted identity.');
        }
    }

    public static function fromAcademicPeriod(AcademicPeriod $period): self
    {
        $id = $period->id();
        if ($id === null) {
            throw new RuntimeException('Reporting AcademicPeriod requires a persisted identity.');
        }

        return new self(
            $id->value(),
            $period->code()->value(),
            $period->name()->value(),
            $period->dates()->startsOn()->format('Y-m-d'),
            $period->dates()->endsOn()->format('Y-m-d'),
            $period->status(),
        );
    }
}
