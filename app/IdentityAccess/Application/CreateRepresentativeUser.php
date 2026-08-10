<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Dto\RepresentativeUserOutput;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserAlreadyExists;
use App\IdentityAccess\Application\Exception\RepresentativeUserPersonNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class CreateRepresentativeUser
{
    public function __construct(
        private RepresentativeRepository $representatives,
        private PersonRepository $persons,
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private RepresentativePasswordPolicy $passwordPolicy,
    ) {
    }

    public function handle(CreateRepresentativeUserInput $input): RepresentativeUserOutput
    {
        $representative = $this->representatives->findById(
            new RepresentativeId($input->representativeId)
        );
        if ($representative === null) {
            throw new RepresentativeNotFound('Representative was not found.');
        }

        $personId = new PersonId($representative->personId()->value());
        $person = $this->persons->findById($personId);
        if ($person === null) {
            throw new RepresentativeUserPersonNotFound(
                'Representative references a Person that was not found.'
            );
        }

        $identification = $person->identification();
        if ($identification === null) {
            throw new RepresentativeUserRequiresIdentification(
                'Representative User requires complete Person identification.'
            );
        }

        $userPersonId = new UserPersonId($personId->value());
        if ($this->users->findByPersonId($userPersonId) !== null) {
            throw new RepresentativeUserAlreadyExists(
                'Representative Person already has a User.'
            );
        }

        $loginIdentifier = new LoginIdentifier($identification->documentNumber());
        if ($this->users->findByLoginIdentifier($loginIdentifier) !== null) {
            throw new RepresentativeLoginIdentifierAlreadyUsed(
                'Representative login identifier is already in use.'
            );
        }

        $this->passwordPolicy->assertValid($input->plainTextPassword);
        $passwordHash = new PasswordHash(
            $this->passwordHasher->hash($input->plainTextPassword)
        );
        $persisted = $this->users->save(new User(
            null,
            $userPersonId,
            $loginIdentifier,
            $passwordHash,
            $input->status,
        ));

        return RepresentativeUserOutput::fromUser(
            $persisted,
            expectedPersonId: $userPersonId,
        );
    }
}
