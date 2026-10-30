<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\Shared\Application\TransactionManager;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Domain\ValueObject\PersonId;

final readonly class GrantInstitutionalReportingAccessToExistingUser
{
    public function __construct(
        private TransactionManager $transactions,
        private InstitutionalReportingPersonEligibility $eligibility,
        private LockingUserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
        private InstitutionalReportingCapabilityGrant $capabilityGrant,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    public function handle(int $personId): bool
    {
        $changed = $this->transactions->transactional(function () use ($personId): bool {
            $this->eligibility->lockEligible($personId);
            $user = $this->users->findByPersonIdForUpdate(new PersonId($personId));
            if ($user === null || $user->id() === null) {
                throw new InstitutionalReportingAccessConflict('Eligible ACTIVE User was not found.');
            }
            if ($this->dedicatedProfile->isExactLegacyAdmin($user) || $user->isDisabled()) {
                throw new InstitutionalReportingAccessConflict('User cannot be designated for institutional reporting.');
            }
            if ($this->dedicatedProfile->handle($user)) {
                return false;
            }

            $this->capabilityGrant->grant($user->id());

            return true;
        });

        if ($changed) {
            $this->securityEvents->record('institutional_reporting.access_granted');
        }

        return $changed;
    }
}
