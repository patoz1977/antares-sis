<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Application\LockRepresentativeUserForAdministration;
use App\IdentityAccess\Application\LockingUserRepository;
use App\Shared\Application\TransactionManager;

final readonly class ChangeRepresentativeUserStatus
{
    public function __construct(
        private TransactionManager $transactions,
        private LockingUserRepository $users,
        private LockRepresentativeUserForAdministration $representativeUser,
    ) {
    }

    public function handle(
        int $userId,
        int $representativeId,
        int $personId,
        bool $activate,
    ): bool
    {
        return $this->transactions->transactional(function () use (
            $userId,
            $representativeId,
            $personId,
            $activate,
        ): bool {
            try {
                $user = $this->representativeUser->lock($representativeId, $personId, $userId);
            } catch (RepresentativeUserNotFound) {
                throw new AdministrativeUserOperationNotAllowed('User is not a Representative account.');
            }

            $changed = $activate ? $user->reactivate() : $user->disable();
            if ($changed) {
                $this->users->save($user);
            }

            return $changed;
        });
    }
}
