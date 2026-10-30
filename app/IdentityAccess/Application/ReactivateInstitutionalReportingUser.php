<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\Shared\Application\TransactionManager;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Domain\ValueObject\PersonId;

final readonly class ReactivateInstitutionalReportingUser
{
    public function __construct(
        private TransactionManager $transactions,
        private InstitutionalReportingPersonEligibility $eligibility,
        private LockingUserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    public function handle(int $personId): bool
    {
        $changed = $this->transactions->transactional(function () use ($personId): bool {
            $this->eligibility->lockEligible($personId);
            $user = $this->users->findByPersonIdForUpdate(new PersonId($personId));
            if ($user === null || !$this->dedicatedProfile->handle($user)) {
                throw new InstitutionalReportingAccessConflict('Dedicated reporting User was not found.');
            }
            if (!$user->reactivate()) {
                return false;
            }
            $this->users->save($user);

            return true;
        });

        if ($changed) {
            $this->securityEvents->record('institutional_reporting.reactivated');
        }

        return $changed;
    }
}
