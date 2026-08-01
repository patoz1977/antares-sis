<?php

declare(strict_types=1);

namespace App\Person\Application;

use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use DateTimeImmutable;

final readonly class CreatePerson
{
    public function __construct(private PersonRepository $persons)
    {
    }

    public function handle(CreatePersonInput $input, DateTimeImmutable $today): PersonOutput
    {
        $identification = $this->identification($input->documentTypeId, $input->documentNumber);
        $person = new Person(
            null,
            new PersonalName(
                $input->firstName,
                $input->middleName,
                $input->firstSurname,
                $input->secondSurname,
            ),
            $identification,
            $input->birthDate,
            $input->sexId,
            $input->maritalStatusId,
            $input->educationLevelId,
            $this->contactInformation($input->email, $input->mobilePhone, $input->landlinePhone),
            $input->status,
            $today->setTime(0, 0),
        );

        if ($identification !== null && $this->persons->findByIdentification($identification) !== null) {
            throw new IdentificationAlreadyUsed('Person identification is already in use.');
        }

        return PersonOutput::fromPerson($this->persons->save($person));
    }

    private function identification(?int $documentTypeId, ?string $documentNumber): ?Identification
    {
        if ($documentTypeId === null && $documentNumber === null) {
            return null;
        }

        return new Identification($documentTypeId ?? 0, $documentNumber ?? '');
    }

    private function contactInformation(
        ?string $email,
        ?string $mobilePhone,
        ?string $landlinePhone,
    ): ?ContactInformation {
        $contact = new ContactInformation($email, $mobilePhone, $landlinePhone);

        return $contact->email() === null
            && $contact->mobilePhone() === null
            && $contact->landlinePhone() === null
                ? null
                : $contact;
    }
}
