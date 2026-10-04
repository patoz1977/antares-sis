<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery;

enum PersonDiscoveryField: string
{
    case FirstName = 'first_name';
    case MiddleName = 'middle_name';
    case FirstSurname = 'first_surname';
    case SecondSurname = 'second_surname';
    case IdentificationNumber = 'identification_number';
    case PersonalEmail = 'personal_email';

    public function isName(): bool
    {
        return match ($this) {
            self::FirstName, self::MiddleName, self::FirstSurname, self::SecondSurname => true,
            default => false,
        };
    }
}
