<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain;

enum AcademicPeriodStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
