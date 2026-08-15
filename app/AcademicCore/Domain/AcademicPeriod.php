<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain;

use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;

final class AcademicPeriod
{
    public function __construct(
        private readonly ?AcademicPeriodId $id,
        private readonly AcademicPeriodCode $code,
        private readonly AcademicPeriodName $name,
        private readonly AcademicPeriodDateRange $dates,
        private AcademicPeriodStatus $status,
    ) {
    }

    public function id(): ?AcademicPeriodId
    {
        return $this->id;
    }

    public function code(): AcademicPeriodCode
    {
        return $this->code;
    }

    public function name(): AcademicPeriodName
    {
        return $this->name;
    }

    public function dates(): AcademicPeriodDateRange
    {
        return $this->dates;
    }

    public function status(): AcademicPeriodStatus
    {
        return $this->status;
    }

    public function activate(): void
    {
        $this->status = AcademicPeriodStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = AcademicPeriodStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === AcademicPeriodStatus::Active;
    }
}
