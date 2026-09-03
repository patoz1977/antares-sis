<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Orchestration;

use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessInput;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessForPersonInput;
use App\IdentityAccess\Application\Orchestration\Dto\RepresentativeAccessOutput;
use App\IdentityAccess\Domain\UserStatus;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\GetPerson;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Domain\RepresentativeStatus;
use DateTimeImmutable;

/**
 * Transaction-aware composition primitive. Its caller owns the outer transaction.
 */
final readonly class CreateRepresentativeAccess
{
    public function __construct(
        private CreatePerson $createPerson,
        private GetPerson $getPerson,
        private CreateRepresentative $createRepresentative,
        private CreateRepresentativeUser $createRepresentativeUser,
    ) {
    }

    public function handle(
        CreateRepresentativeAccessInput $input,
        DateTimeImmutable $today,
    ): RepresentativeAccessOutput {
        $person = $this->createPerson->handle($input->person, $today);
        return $this->createForPersistedPerson(
            $person,
            $input->occupation,
            $input->companyName,
            $input->position,
            $input->workPhone,
            $input->workEmail,
            $input->representativeStatus,
            $input->plainTextPassword,
            $input->userStatus,
        );
    }

    public function handleForExistingPerson(
        CreateRepresentativeAccessForPersonInput $input,
    ): RepresentativeAccessOutput {
        return $this->createForPersistedPerson(
            $this->getPerson->handle($input->personId),
            $input->occupation,
            $input->companyName,
            $input->position,
            $input->workPhone,
            $input->workEmail,
            $input->representativeStatus,
            $input->plainTextPassword,
            $input->userStatus,
        );
    }

    private function createForPersistedPerson(
        PersonOutput $person,
        ?string $occupation,
        ?string $companyName,
        ?string $position,
        ?string $workPhone,
        ?string $workEmail,
        RepresentativeStatus $representativeStatus,
        string $plainTextPassword,
        UserStatus $userStatus,
    ): RepresentativeAccessOutput {
        $representative = $this->createRepresentative->handle(new CreateRepresentativeInput(
            $person->id,
            $occupation,
            $companyName,
            $position,
            $workPhone,
            $workEmail,
            $representativeStatus,
        ));
        $user = $this->createRepresentativeUser->handle(new CreateRepresentativeUserInput(
            $representative->id,
            $plainTextPassword,
            $userStatus,
        ));

        return new RepresentativeAccessOutput($person, $representative, $user);
    }
}
