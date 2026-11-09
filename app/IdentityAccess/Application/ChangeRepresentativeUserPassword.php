<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\RepresentativeUserOutput;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Shared\Application\TransactionManager;

final readonly class ChangeRepresentativeUserPassword
{
    public function __construct(
        private TransactionManager $transactions,
        private LockRepresentativeUserForAdministration $representativeUser,
        private LockingUserRepository $users,
        private PasswordHasher $passwordHasher,
        private RepresentativePasswordPolicy $passwordPolicy,
    ) {
    }

    public function handle(
        ChangeRepresentativeUserPasswordInput $input,
    ): RepresentativeUserOutput {
        $personIdValue = $this->representativeUser->resolvePersonId($input->representativeId);
        $this->passwordPolicy->assertValid($input->newPlainTextPassword);
        $passwordHash = new PasswordHash($this->passwordHasher->hash(
            $input->newPlainTextPassword
        ));

        return $this->transactions->transactional(function () use (
            $input,
            $personIdValue,
            $passwordHash,
        ): RepresentativeUserOutput {
            $user = $this->representativeUser->lock(
                $input->representativeId,
                $personIdValue,
            );
            $userId = $user->id();
            if ($userId === null) {
                throw new RepresentativeUserNotFound(
                    'Representative User does not have a persisted identity.'
                );
            }

            $personId = new UserPersonId($personIdValue);
            $user->changePasswordHash($passwordHash);

            return RepresentativeUserOutput::fromUser(
                $this->users->save($user),
                $userId,
                $personId,
            );
        });
    }
}
