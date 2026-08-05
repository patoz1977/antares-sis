<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration;

use App\Family\Application\CreateFamily;
use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\RepresentativeFamilyOutput;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use Core\Application\TransactionRunner;
use DateTimeImmutable;

final readonly class CreateRepresentativeFamily
{
    public function __construct(
        private TransactionRunner $transactions,
        private CreatePerson $createPerson,
        private CreateRepresentative $createRepresentative,
        private CreateFamily $createFamily,
    ) {
    }

    public function handle(
        CreateRepresentativeFamilyInput $input,
        DateTimeImmutable $today,
    ): RepresentativeFamilyOutput {
        return $this->transactions->run(function () use ($input, $today): RepresentativeFamilyOutput {
            $person = $this->createPerson->handle(new CreatePersonInput(
                $input->firstName,
                $input->middleName,
                $input->firstSurname,
                $input->secondSurname,
                $input->documentTypeId,
                $input->documentNumber,
                $input->birthDate,
                $input->sexId,
                $input->maritalStatusId,
                $input->educationLevelId,
                $input->email,
                $input->mobilePhone,
                $input->landlinePhone,
                $input->personStatus,
            ), $today);
            if ($person->id <= 0) {
                throw new InvalidPersistedPersonResult(
                    'Composite operation received an invalid persisted Person identity.'
                );
            }

            $representative = $this->createRepresentative->handle(new CreateRepresentativeInput(
                $person->id,
                $input->occupation,
                $input->companyName,
                $input->position,
                $input->workPhone,
                $input->workEmail,
                $input->representativeStatus,
            ));
            if ($representative->id <= 0) {
                throw new InvalidPersistedRepresentativeResult(
                    'Composite operation received an invalid persisted Representative identity.'
                );
            }

            $family = $this->createFamily->handle(new CreateFamilyInput(
                $input->displayName,
                $input->familyStatus,
                $representative->id,
                $input->relationshipTypeId,
                $input->startedAt,
            ));
            if ($family->id <= 0) {
                throw new InvalidPersistedFamilyResult(
                    'Composite operation received an invalid persisted Family identity.'
                );
            }

            return new RepresentativeFamilyOutput($person, $representative, $family);
        });
    }
}
