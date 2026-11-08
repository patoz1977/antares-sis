<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\InstitutionalReportingPersonEligibility;
use App\IdentityAccess\Application\IsDedicatedInstitutionalReportingUser;
use App\IdentityAccess\Application\LockingUserRepository;
use App\IdentityAccess\Application\Security\InstitutionalReportingPasswordPolicy;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\Shared\Application\TransactionManager;

final readonly class ChangeInstitutionalReportingUserPassword
{
    public function __construct(
        private TransactionManager $transactions,
        private InstitutionalReportingPersonEligibility $eligibility,
        private LockingUserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
        private PasswordHasher $passwordHasher,
        private InstitutionalReportingPasswordPolicy $passwordPolicy,
    ) {
    }

    public function handle(int $personId, string $plainTextPassword): void
    {
        $this->passwordPolicy->assertValid($plainTextPassword);
        $passwordHash = new PasswordHash($this->passwordHasher->hash($plainTextPassword));

        $this->transactions->transactional(function () use ($personId, $passwordHash): void {
            $this->eligibility->lockEligible($personId);
            $user = $this->users->findByPersonIdForUpdate(new PersonId($personId));
            if ($user === null || !$this->dedicatedProfile->handleForUpdate($user)) {
                throw new AdministrativeUserOperationNotAllowed('Dedicated reporting User was not found.');
            }
            $user->changePasswordHash($passwordHash);
            $this->users->save($user);
        });
    }
}
