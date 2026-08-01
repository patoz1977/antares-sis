<?php

declare(strict_types=1);

namespace App\Person\Application\Dto;

use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;

final readonly class PersonOutput
{
    public function __construct(
        public int $id,
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public ?int $documentTypeId,
        public ?string $documentNumber,
        public DateTimeImmutable $birthDate,
        public int $sexId,
        public ?int $maritalStatusId,
        public ?int $educationLevelId,
        public ?string $email,
        public ?string $mobilePhone,
        public ?string $landlinePhone,
        public PersonStatus $status,
    ) {
    }

    public static function fromPerson(Person $person, ?PersonId $expectedId = null): self
    {
        $id = $person->id();
        if ($id === null || ($expectedId !== null && !$id->equals($expectedId))) {
            throw new InvalidPersistedPersonResult(
                'Person repository returned an invalid persisted identity.'
            );
        }

        $name = $person->personalName();
        $identification = $person->identification();
        $contact = $person->contactInformation();

        return new self(
            $id->value(),
            $name->firstName(),
            $name->middleName(),
            $name->firstSurname(),
            $name->secondSurname(),
            $identification?->documentTypeId(),
            $identification?->documentNumber(),
            $person->birthDate(),
            $person->sexId(),
            $person->maritalStatusId(),
            $person->educationLevelId(),
            $contact?->email(),
            $contact?->mobilePhone(),
            $contact?->landlinePhone(),
            $person->status(),
        );
    }
}
