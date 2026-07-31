<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}
