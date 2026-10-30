<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\Shared\Application\TransactionManager;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;

final readonly class DisableInstitutionalReportingUser
{
    public function __construct(
        private TransactionManager $transactions,
        private PersonRepository $persons,
        private LockingUserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    public function handle(int $personId): bool
    {
        $changed = $this->transactions->transactional(function () use ($personId): bool {
            if ($this->persons->findByIdForUpdate(new PersonDomainId($personId)) === null) {
                throw new InstitutionalReportingAccessConflict('Institutional reporting Person was not found.');
            }
            $user = $this->users->findByPersonIdForUpdate(new PersonId($personId));
            if ($user === null || !$this->dedicatedProfile->handle($user)) {
                throw new InstitutionalReportingAccessConflict('Dedicated reporting User was not found.');
            }
            if (!$user->disable()) {
                return false;
            }
            $this->users->save($user);

            return true;
        });

        if ($changed) {
            $this->securityEvents->record('institutional_reporting.disabled');
        }

        return $changed;
    }
}
