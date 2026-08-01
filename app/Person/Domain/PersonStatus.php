<?php

declare(strict_types=1);

namespace App\Person\Domain;

enum PersonStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
