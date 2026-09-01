<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration;

use App\Family\Application\CreateFamily;
use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\RepresentativeFamilyOutput;
use App\IdentityAccess\Application\Orchestration\CreateRepresentativeAccess;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessInput;
use App\Person\Application\Dto\CreatePersonInput;
use Core\Application\TransactionRunner;
use DateTimeImmutable;

final readonly class CreateRepresentativeFamily
{
    public function __construct(
        private TransactionRunner $transactions,
        private CreateRepresentativeAccess $createRepresentativeAccess,
        private CreateFamily $createFamily,
    ) {
    }

    public function handle(
        CreateRepresentativeFamilyInput $input,
        DateTimeImmutable $today,
    ): RepresentativeFamilyOutput {
        return $this->transactions->run(function () use ($input, $today): RepresentativeFamilyOutput {
            $access = $this->createRepresentativeAccess->handle(new CreateRepresentativeAccessInput(
                new CreatePersonInput(
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
                ),
                $input->occupation,
                $input->companyName,
                $input->position,
                $input->workPhone,
                $input->workEmail,
                $input->representativeStatus,
                $input->initialPassword,
                $input->userStatus,
            ), $today);

            $family = $this->createFamily->handle(new CreateFamilyInput(
                $input->displayName,
                $input->familyStatus,
                $access->representative->id,
                $input->relationshipTypeId,
                $input->startedAt,
            ));
            if ($family->id <= 0) {
                throw new InvalidPersistedFamilyResult(
                    'Composite operation received an invalid persisted Family identity.'
                );
            }

            return new RepresentativeFamilyOutput(
                $access->person,
                $access->representative,
                $access->user,
                $family,
            );
        });
    }
}
