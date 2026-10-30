<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\Shared\Application\TransactionManager;
use App\IdentityAccess\Application\Dto\ProvisionInstitutionalReportingUserInput;
use App\IdentityAccess\Application\Dto\InstitutionalReportingUserOutput;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Application\Exception\InstitutionalReportingLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\InstitutionalReportingReservedLoginIdentifier;
use App\IdentityAccess\Application\Security\InstitutionalReportingPasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;

final readonly class ProvisionInstitutionalReportingUser
{
    private const RESERVED_ADMIN_LOGIN = 'admin';

    public function __construct(
        private TransactionManager $transactions,
        private InstitutionalReportingPersonEligibility $eligibility,
        private LockingUserRepository $users,
        private InstitutionalReportingPasswordPolicy $passwordPolicy,
        private PasswordHasher $passwordHasher,
        private InstitutionalReportingCapabilityGrant $capabilityGrant,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    public function handle(ProvisionInstitutionalReportingUserInput $input): InstitutionalReportingUserOutput
    {
        $output = $this->transactions->transactional(function () use ($input): InstitutionalReportingUserOutput {
            $this->eligibility->lockEligible($input->personId);
            $personId = new PersonId($input->personId);
            if ($this->users->findByPersonIdForUpdate($personId) !== null) {
                throw new InstitutionalReportingAccessConflict('Person already has a User.');
            }

            $login = new LoginIdentifier($input->loginIdentifier);
            if ($login->value() === self::RESERVED_ADMIN_LOGIN) {
                throw new InstitutionalReportingReservedLoginIdentifier(
                    'Legacy Admin LoginIdentifier is reserved.'
                );
            }
            if ($this->users->findByLoginIdentifierForUpdate($login) !== null) {
                throw new InstitutionalReportingLoginIdentifierAlreadyUsed(
                    'Institutional reporting LoginIdentifier is already used.'
                );
            }

            $this->passwordPolicy->assertValid($input->plainTextPassword);
            $persisted = $this->users->save(new User(
                null,
                $personId,
                $login,
                new PasswordHash($this->passwordHasher->hash($input->plainTextPassword)),
                UserStatus::Active,
            ));
            $userId = $persisted->id();
            if (!$userId instanceof UserId || $persisted->personId()->value() !== $input->personId) {
                throw new InstitutionalReportingAccessConflict('Persisted reporting User is incoherent.');
            }

            $this->capabilityGrant->grant($userId);

            return InstitutionalReportingUserOutput::fromUser($persisted, $userId, $personId);
        });

        $this->securityEvents->record('institutional_reporting.provisioned');

        return $output;
    }
}
