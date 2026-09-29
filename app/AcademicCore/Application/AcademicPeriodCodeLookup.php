<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;

interface AcademicPeriodCodeLookup
{
    public function findByCode(AcademicPeriodCode $code): ?AcademicPeriod;
}
