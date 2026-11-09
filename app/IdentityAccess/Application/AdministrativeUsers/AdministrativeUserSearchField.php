<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

enum AdministrativeUserSearchField: string
{
    case Name = 'name';
    case Identification = 'identification_number';
    case LoginIdentifier = 'login_identifier';
}
