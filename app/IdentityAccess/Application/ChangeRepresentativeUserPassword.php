<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\RepresentativeUserOutput;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class ChangeRepresentativeUserPassword
{
    public function __construct(
        private RepresentativeRepository $representatives,
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private RepresentativePasswordPolicy $passwordPolicy,
    ) {
    }

    public function handle(
        ChangeRepresentativeUserPasswordInput $input,
    ): RepresentativeUserOutput {
        $representative = $this->representatives->findById(
            new RepresentativeId($input->representativeId)
        );
        if ($representative === null) {
            throw new RepresentativeNotFound('Representative was not found.');
        }

        $personId = new UserPersonId($representative->personId()->value());
        $user = $this->users->findByPersonId($personId);
        if ($user === null) {
            throw new RepresentativeUserNotFound('Representative User was not found.');
        }

        $userId = $user->id();
        if ($userId === null) {
            throw new RepresentativeUserNotFound(
                'Representative User does not have a persisted identity.'
            );
        }

        $this->passwordPolicy->assertValid($input->newPlainTextPassword);
        $user->changePasswordHash(new PasswordHash(
            $this->passwordHasher->hash($input->newPlainTextPassword)
        ));

        return RepresentativeUserOutput::fromUser(
            $this->users->save($user),
            $userId,
            $personId,
        );
    }
}
