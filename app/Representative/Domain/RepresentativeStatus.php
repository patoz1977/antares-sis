<?php

declare(strict_types=1);

namespace App\Representative\Domain;

enum RepresentativeStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
