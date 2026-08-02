<?php

declare(strict_types=1);

namespace App\Family\Domain;

enum FamilyStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
