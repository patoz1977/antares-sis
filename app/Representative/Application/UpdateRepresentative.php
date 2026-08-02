<?php

declare(strict_types=1);

namespace App\Representative\Application;

use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Application\Dto\UpdateRepresentativeInput;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class UpdateRepresentative
{
    public function __construct(private RepresentativeRepository $representatives)
    {
    }

    public function handle(UpdateRepresentativeInput $input): RepresentativeOutput
    {
        $id = new RepresentativeId($input->representativeId);
        $representative = $this->representatives->findById($id);
        if ($representative === null) {
            throw new RepresentativeNotFound('Representative was not found.');
        }

        $employment = new EmploymentInformation(
            $input->occupation,
            $input->companyName,
            $input->position,
            $input->workPhone,
            $input->workEmail,
        );

        $representative->replaceEmploymentInformation($employment->isEmpty() ? null : $employment);
        match ($input->status) {
            RepresentativeStatus::Active => $representative->activate(),
            RepresentativeStatus::Inactive => $representative->deactivate(),
        };

        $persisted = $this->representatives->save($representative);
        $persistedId = $persisted->id();
        if ($persistedId === null || !$persistedId->equals($id)) {
            throw new InvalidPersistedRepresentativeResult(
                'Representative repository returned an invalid persisted identity.'
            );
        }

        return RepresentativeOutput::fromRepresentative($persisted, $persistedId);
    }
}
