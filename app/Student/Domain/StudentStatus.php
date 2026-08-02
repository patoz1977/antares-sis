<?php

declare(strict_types=1);

namespace App\Student\Domain;

enum StudentStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
