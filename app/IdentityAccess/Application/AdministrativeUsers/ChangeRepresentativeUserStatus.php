<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserNotFound;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\LockingUserRepository;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Shared\Application\TransactionManager;

final readonly class ChangeRepresentativeUserStatus
{
    public function __construct(
        private TransactionManager $transactions,
        private LockingUserRepository $users,
        private RepresentativeRepository $representatives,
    ) {
    }

    public function handle(int $userId, bool $activate): bool
    {
        return $this->transactions->transactional(function () use ($userId, $activate): bool {
            $user = $this->users->findByIdForUpdate(new UserId($userId));
            if ($user === null) {
                throw new AdministrativeUserNotFound('User was not found.');
            }
            if ($user->loginIdentifier()->value() === 'admin'
                || $this->representatives->findByPersonId(
                    new RepresentativePersonId($user->personId()->value())
                ) === null
            ) {
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
