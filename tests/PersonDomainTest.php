<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;
use Tests\Support\TestRunner;

function registerPersonDomainTests(TestRunner $runner): void
{
    $runner->add('PersonId requires a positive identity and compares by value', function (): void {
        $left = new PersonId(10);
        $right = new PersonId(10);

        assertSameValue(10, $left->value());
        assertSameValue(true, $left->equals($right));
        assertSameValue(false, $left->equals(new PersonId(11)));
        assertThrows(static fn (): PersonId => new PersonId(0), InvalidPersonState::class);
        assertThrows(static fn (): PersonId => new PersonId(-1), InvalidPersonState::class);
    });

    $runner->add('PersonalName normalizes its coherent required and optional parts', function (): void {
        $name = new PersonalName('  Ana  ', '  María ', ' Pérez ', '   ');

        assertSameValue('Ana', $name->firstName());
        assertSameValue('María', $name->middleName());
        assertSameValue('Pérez', $name->firstSurname());
        assertSameValue(null, $name->secondSurname());
        assertSameValue(true, $name->equals(new PersonalName('Ana', 'María', 'Pérez', null)));
        assertSameValue(false, $name->equals(new PersonalName('Ana', null, 'Pérez', null)));
    });

    $runner->add('PersonalName rejects missing or oversized parts', function (): void {
        assertThrows(
            static fn (): PersonalName => new PersonalName(' ', null, 'Pérez', null),
            InvalidPersonState::class
        );
        assertThrows(
            static fn (): PersonalName => new PersonalName('Ana', null, ' ', null),
            InvalidPersonState::class
        );
        assertThrows(
            static fn (): PersonalName => new PersonalName(str_repeat('a', 101), null, 'Pérez', null),
            InvalidPersonState::class
        );
        assertThrows(
            static fn (): PersonalName => new PersonalName('Ana', null, 'Pérez', str_repeat('a', 101)),
            InvalidPersonState::class
        );
    });

    $runner->add('Identification preserves its complete document pair and compares by value', function (): void {
        $identification = new Identification(2, '  0912345678  ');

        assertSameValue(2, $identification->documentTypeId());
        assertSameValue('0912345678', $identification->documentNumber());
        assertSameValue(true, $identification->equals(new Identification(2, '0912345678')));
        assertSameValue(false, $identification->equals(new Identification(3, '0912345678')));
    });

    $runner->add('Identification rejects an incomplete or oversized document pair', function (): void {
        assertThrows(static fn (): Identification => new Identification(0, '0912345678'), InvalidPersonState::class);
        assertThrows(static fn (): Identification => new Identification(1, ' '), InvalidPersonState::class);
        assertThrows(
            static fn (): Identification => new Identification(1, str_repeat('1', 51)),
            InvalidPersonState::class
        );
    });

    $runner->add('ContactInformation normalizes optional contact data without phone format rules', function (): void {
        $maximumLengthPhone = str_repeat('x', 30);
        $contact = new ContactInformation(' ana@example.com ', " $maximumLengthPhone ", ' desk: ext. ABC/42 ');

        assertSameValue('ana@example.com', $contact->email());
        assertSameValue($maximumLengthPhone, $contact->mobilePhone());
        assertSameValue('desk: ext. ABC/42', $contact->landlinePhone());
        assertSameValue(
            true,
            $contact->equals(new ContactInformation('ana@example.com', $maximumLengthPhone, 'desk: ext. ABC/42'))
        );

        $empty = new ContactInformation(' ', null, '');
        assertSameValue(null, $empty->email());
        assertSameValue(null, $empty->mobilePhone());
        assertSameValue(null, $empty->landlinePhone());
    });

    $runner->add('ContactInformation rejects invalid email and phones longer than 30 characters', function (): void {
        assertThrows(
            static fn (): ContactInformation => new ContactInformation('invalid-email', null, null),
            InvalidPersonState::class
        );
        assertThrows(
            static fn (): ContactInformation => new ContactInformation(null, str_repeat('x', 31), null),
            InvalidPersonState::class
        );
    });

    $runner->add('Person represents the complete approved identity state', function (): void {
        $person = personDomainFixture();

        assertSameValue(1, $person->id()->value());
        assertSameValue('Ana', $person->personalName()->firstName());
        assertSameValue('0912345678', $person->identification()?->documentNumber());
        assertSameValue('2010-05-20', $person->birthDate()->format('Y-m-d'));
        assertSameValue('00:00:00', $person->birthDate()->format('H:i:s'));
        assertSameValue(1, $person->sexId());
        assertSameValue(2, $person->maritalStatusId());
        assertSameValue(3, $person->educationLevelId());
        assertSameValue('ana@example.com', $person->contactInformation()?->email());
        assertSameValue(PersonStatus::Active, $person->status());
        assertSameValue(true, $person->isActive());
    });

    $runner->add('Person may exist without Identification or ContactInformation', function (): void {
        $person = new Person(
            new PersonId(2),
            new PersonalName('Luis', null, 'Vega', null),
            null,
            new DateTimeImmutable('2012-01-01'),
            1,
            null,
            null,
            null,
            PersonStatus::Active,
            new DateTimeImmutable('2026-08-01'),
        );

        assertSameValue(null, $person->identification());
        assertSameValue(null, $person->contactInformation());
        assertSameValue(null, $person->maritalStatusId());
        assertSameValue(null, $person->educationLevelId());
    });

    $runner->add('Person rejects future birth dates and invalid Catalog identities', function (): void {
        assertThrows(
            static fn (): Person => personDomainFixture(birthDate: '2026-08-02'),
            InvalidPersonState::class
        );
        assertThrows(static fn (): Person => personDomainFixture(sexId: 0), InvalidPersonState::class);
        assertThrows(static fn (): Person => personDomainFixture(maritalStatusId: 0), InvalidPersonState::class);
        assertThrows(static fn (): Person => personDomainFixture(educationLevelId: -1), InvalidPersonState::class);
    });

    $runner->add('Person replaces identity data as one coherent value set', function (): void {
        $person = personDomainFixture();
        $newName = new PersonalName('Lucía', null, 'Torres', 'Mora');
        $newIdentification = new Identification(4, 'PASS-100');

        $person->updateIdentity(
            $newName,
            $newIdentification,
            new DateTimeImmutable('2009-02-03 15:45:00'),
            5,
            null,
            6,
            new DateTimeImmutable('2026-08-01'),
        );

        assertSameValue(true, $person->personalName()->equals($newName));
        assertSameValue(true, $person->identification()?->equals($newIdentification));
        assertSameValue('2009-02-03', $person->birthDate()->format('Y-m-d'));
        assertSameValue(5, $person->sexId());
        assertSameValue(null, $person->maritalStatusId());
        assertSameValue(6, $person->educationLevelId());
    });

    $runner->add('Person rejects an invalid identity update without partial mutation', function (): void {
        $person = personDomainFixture();
        $originalName = $person->personalName();
        $originalBirthDate = $person->birthDate();

        assertThrows(
            static function () use ($person): void {
                $person->updateIdentity(
                    new PersonalName('Changed', null, 'Name', null),
                    null,
                    new DateTimeImmutable('2026-08-02'),
                    0,
                    null,
                    null,
                    new DateTimeImmutable('2026-08-01'),
                );
            },
            InvalidPersonState::class
        );

        assertSameValue(true, $person->personalName()->equals($originalName));
        assertSameValue($originalBirthDate, $person->birthDate());
        assertSameValue(1, $person->sexId());
        assertSameValue('0912345678', $person->identification()?->documentNumber());
    });

    $runner->add('Person replaces and removes optional ContactInformation', function (): void {
        $person = personDomainFixture();
        $newContact = new ContactInformation('new@example.com', null, '02 345 6789');

        $person->updateContactInformation($newContact);
        assertSameValue(true, $person->contactInformation()?->equals($newContact));

        $person->updateContactInformation(null);
        assertSameValue(null, $person->contactInformation());
    });

    $runner->add('Person activation lifecycle uses only GENERAL_STATUS codes', function (): void {
        $person = personDomainFixture();

        $person->deactivate();
        assertSameValue(PersonStatus::Inactive, $person->status());
        assertSameValue(false, $person->isActive());

        $person->activate();
        assertSameValue(PersonStatus::Active, $person->status());
        assertSameValue(true, $person->isActive());
        assertSameValue('ACTIVE', PersonStatus::Active->value);
        assertSameValue('INACTIVE', PersonStatus::Inactive->value);
    });
}

function personDomainFixture(
    string $birthDate = '2010-05-20',
    int $sexId = 1,
    ?int $maritalStatusId = 2,
    ?int $educationLevelId = 3,
): Person {
    return new Person(
        new PersonId(1),
        new PersonalName('Ana', 'María', 'Pérez', 'López'),
        new Identification(1, '0912345678'),
        new DateTimeImmutable($birthDate),
        $sexId,
        $maritalStatusId,
        $educationLevelId,
        new ContactInformation('ana@example.com', '+593 99 123 4567', '02 234 5678'),
        PersonStatus::Active,
        new DateTimeImmutable('2026-08-01'),
    );
}
