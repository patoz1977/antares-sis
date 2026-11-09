<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;
use App\Shared\Application\DedicatedInstitutionalReportingProfile;

final readonly class LockRepresentativeUserForAdministration
{
    public function __construct(
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
        private LockingUserRepository $users,
        private DedicatedInstitutionalReportingProfile $dedicatedReportingProfile,
    ) {
    }

    public function resolvePersonId(int $representativeId): int
    {
        $representative = $this->representatives->findById(new RepresentativeId($representativeId));
        if ($representative === null) {
            throw new RepresentativeNotFound('Representative was not found.');
        }

        return $representative->personId()->value();
    }

    public function lock(
        int $representativeId,
        int $expectedPersonId,
        ?int $expectedUserId = null,
    ): User {
        if ($this->persons->findByIdForUpdate(new PersonId($expectedPersonId)) === null) {
            throw new RepresentativeUserNotFound('Representative Person was not found.');
        }

        $representative = $this->representatives->findByIdForUpdate(
            new RepresentativeId($representativeId)
        );
        if ($representative === null || $representative->personId()->value() !== $expectedPersonId) {
            throw new RepresentativeUserNotFound('Representative identity is no longer coherent.');
        }

        $user = $this->users->findByPersonIdForUpdate(new UserPersonId($expectedPersonId));
        $userId = $user?->id();
        if ($user === null
            || $userId === null
            || ($expectedUserId !== null && $userId->value() !== $expectedUserId)
            || $user->loginIdentifier()->value() === 'admin'
            || $this->dedicatedReportingProfile->isAssignedToPerson($expectedPersonId)
        ) {
            throw new RepresentativeUserNotFound('Representative User is not administrable.');
        }

        return $user;
    }
}
