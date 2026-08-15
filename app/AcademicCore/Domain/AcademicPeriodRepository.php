<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain;

use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;

interface AcademicPeriodRepository
{
    public function findById(AcademicPeriodId $id): ?AcademicPeriod;

    public function findActive(): ?AcademicPeriod;

    public function save(AcademicPeriod $period): AcademicPeriod;

    public function lockOperationalTransition(): void;
}
