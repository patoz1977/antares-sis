<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\Shared\Application\DedicatedInstitutionalReportingProfile;

final readonly class IsDedicatedInstitutionalReportingUser implements DedicatedInstitutionalReportingProfile
{
    private const LEGACY_ADMIN_LOGIN = 'admin';

    public function __construct(
        private UserRepository $users,
        private UserCapabilityLookup $capabilities,
    ) {
    }

    public function handle(User $user): bool
    {
        $userId = $user->id();

        return $userId !== null
            && $user->loginIdentifier()->value() !== self::LEGACY_ADMIN_LOGIN
            && $this->capabilities->hasCapability($userId, CanAccessAllReports::CAPABILITY);
    }

    public function isExactLegacyAdmin(User $user): bool
    {
        return $user->loginIdentifier()->value() === self::LEGACY_ADMIN_LOGIN;
    }

    public function isAssignedToPerson(int $personId): bool
    {
        if ($personId <= 0) {
            return false;
        }

        $user = $this->users->findByPersonId(new PersonId($personId));

        return $user !== null && $this->handle($user);
    }
}
