<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Orchestration;

use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessInput;
use App\IdentityAccess\Application\Orchestration\Dto\RepresentativeAccessOutput;
use App\Person\Application\CreatePerson;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use DateTimeImmutable;

/**
 * Transaction-aware composition primitive. Its caller owns the outer transaction.
 */
final readonly class CreateRepresentativeAccess
{
    public function __construct(
        private CreatePerson $createPerson,
        private CreateRepresentative $createRepresentative,
        private CreateRepresentativeUser $createRepresentativeUser,
    ) {
    }

    public function handle(
        CreateRepresentativeAccessInput $input,
        DateTimeImmutable $today,
    ): RepresentativeAccessOutput {
        $person = $this->createPerson->handle($input->person, $today);
        $representative = $this->createRepresentative->handle(new CreateRepresentativeInput(
            $person->id,
            $input->occupation,
            $input->companyName,
            $input->position,
            $input->workPhone,
            $input->workEmail,
            $input->representativeStatus,
        ));
        $user = $this->createRepresentativeUser->handle(new CreateRepresentativeUserInput(
            $representative->id,
            $input->plainTextPassword,
            $input->userStatus,
        ));

        return new RepresentativeAccessOutput($person, $representative, $user);
    }
}
