<?php

declare(strict_types=1);

namespace App\Representative\Application;

use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Application\Exception\RepresentativeAlreadyExistsForPerson;
use App\Representative\Application\Exception\RepresentativePersonNotFound;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;

final readonly class CreateRepresentative
{
    public function __construct(
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
    ) {
    }

    public function handle(CreateRepresentativeInput $input): RepresentativeOutput
    {
        $personDomainId = new PersonDomainId($input->personId);
        $employment = $this->employmentInformation($input);

        if ($this->persons->findById($personDomainId) === null) {
            throw new RepresentativePersonNotFound('Person for Representative was not found.');
        }

        $personId = new PersonId($input->personId);
        if ($this->representatives->findByPersonId($personId) !== null) {
            throw new RepresentativeAlreadyExistsForPerson(
                'Person already has a Representative role.'
            );
        }

        $representative = new Representative(null, $personId, $employment, $input->status);

        $persisted = $this->representatives->save($representative);
        $id = $persisted->id();
        if ($id === null) {
            throw new InvalidPersistedRepresentativeResult(
                'Representative repository returned an invalid persisted identity.'
            );
        }

        return RepresentativeOutput::fromRepresentative($persisted, $id);
    }

    private function employmentInformation(
        CreateRepresentativeInput $input,
    ): ?EmploymentInformation {
        $employment = new EmploymentInformation(
            $input->occupation,
            $input->companyName,
            $input->position,
            $input->workPhone,
            $input->workEmail,
        );

        return $employment->isEmpty() ? null : $employment;
    }
}
