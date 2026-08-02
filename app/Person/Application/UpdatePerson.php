<?php

declare(strict_types=1);

namespace App\Person\Application;

use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;

final readonly class UpdatePerson
{
    public function __construct(private PersonRepository $persons)
    {
    }

    public function handle(UpdatePersonInput $input, DateTimeImmutable $today): PersonOutput
    {
        $id = new PersonId($input->personId);
        $person = $this->persons->findById($id);
        if ($person === null) {
            throw new PersonNotFound('Person was not found.');
        }

        $name = new PersonalName(
            $input->firstName,
            $input->middleName,
            $input->firstSurname,
            $input->secondSurname,
        );
        $identification = $this->identification($input->documentTypeId, $input->documentNumber);
        $contact = $this->contactInformation($input->email, $input->mobilePhone, $input->landlinePhone);

        $this->assertIdentificationAvailable($identification, $id);

        $person->updateIdentity(
            $name,
            $identification,
            $input->birthDate,
            $input->sexId,
            $input->maritalStatusId,
            $input->educationLevelId,
            $today->setTime(0, 0),
        );
        $person->updateContactInformation($contact);
        match ($input->status) {
            PersonStatus::Active => $person->activate(),
            PersonStatus::Inactive => $person->deactivate(),
        };

        return PersonOutput::fromPerson($this->persons->save($person), $id);
    }

    private function assertIdentificationAvailable(
        ?Identification $identification,
        PersonId $currentId,
    ): void {
        if ($identification === null) {
            return;
        }

        $owner = $this->persons->findByIdentification($identification);
        if ($owner === null || $owner->id()?->equals($currentId) === true) {
            return;
        }

        throw new IdentificationAlreadyUsed('Person identification is already in use.');
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
