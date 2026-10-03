<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

enum FamilyDiscoveryField: string
{
    case FamilyCode = 'family_code';
    case DisplayName = 'display_name';
    case PersonFirstName = 'person_first_name';
    case PersonMiddleName = 'person_middle_name';
    case PersonFirstSurname = 'person_first_surname';
    case PersonSecondSurname = 'person_second_surname';
    case PersonIdentificationNumber = 'person_identification_number';
    case PersonPersonalEmail = 'person_personal_email';

    public function personField(): ?\App\Person\Application\Discovery\PersonDiscoveryField
    {
        return match ($this) {
            self::PersonFirstName => \App\Person\Application\Discovery\PersonDiscoveryField::FirstName,
            self::PersonMiddleName => \App\Person\Application\Discovery\PersonDiscoveryField::MiddleName,
            self::PersonFirstSurname => \App\Person\Application\Discovery\PersonDiscoveryField::FirstSurname,
            self::PersonSecondSurname => \App\Person\Application\Discovery\PersonDiscoveryField::SecondSurname,
            self::PersonIdentificationNumber => \App\Person\Application\Discovery\PersonDiscoveryField::IdentificationNumber,
            self::PersonPersonalEmail => \App\Person\Application\Discovery\PersonDiscoveryField::PersonalEmail,
            default => null,
        };
    }
}
