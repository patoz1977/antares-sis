<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\InstitutionalReportingPersonEligibility;
use App\IdentityAccess\Application\IsDedicatedInstitutionalReportingUser;
use App\IdentityAccess\Application\LockingUserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\Shared\Application\TransactionManager;

final readonly class ChangeInstitutionalReportingUserLoginIdentifier
{
    public function __construct(
        private TransactionManager $transactions,
        private InstitutionalReportingPersonEligibility $eligibility,
        private LockingUserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
    ) {
    }

    public function handle(int $personId, string $loginIdentifier): bool
    {
        $identifier = new LoginIdentifier($loginIdentifier);
        if ($identifier->value() === 'admin') {
            throw new AdministrativeUserOperationNotAllowed('The administrator identifier is reserved.');
        }

        return $this->transactions->transactional(function () use ($personId, $identifier): bool {
            $this->eligibility->lockEligible($personId);
            $user = $this->users->findByPersonIdForUpdate(new PersonId($personId));
            if ($user === null || !$this->dedicatedProfile->handleForUpdate($user)) {
                throw new AdministrativeUserOperationNotAllowed('Dedicated reporting User was not found.');
            }
            if ($user->loginIdentifier()->value() === $identifier->value()) {
                return false;
            }

            $existing = $this->users->findByLoginIdentifierForUpdate($identifier);
            if ($existing !== null && $existing->id()?->value() !== $user->id()?->value()) {
                throw new AdministrativeLoginIdentifierAlreadyUsed('Login identifier is already used.');
            }

            $user->changeLoginIdentifier($identifier);
            $this->users->save($user);

            return true;
        });
    }
}
