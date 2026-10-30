<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Dto\InstitutionalReportingAccessState;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;

final readonly class GetInstitutionalReportingAccessState
{
    public function __construct(
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
        private StudentRepository $students,
        private UserRepository $users,
        private IsDedicatedInstitutionalReportingUser $dedicatedProfile,
    ) {
    }

    public function handle(int $personId): InstitutionalReportingAccessState
    {
        $person = $this->persons->findById(new PersonId($personId));
        if ($person === null) {
            throw new InstitutionalReportingAccessConflict('Institutional reporting Person was not found.');
        }

        $representative = $this->representatives->findByPersonId(new RepresentativePersonId($personId));
        $student = $this->students->findByPersonId(new StudentPersonId($personId));
        $user = $this->users->findByPersonId(new UserPersonId($personId));
        $name = $person->personalName();
        $personName = trim(implode(' ', array_filter([
            $name->firstName(),
            $name->middleName(),
            $name->firstSurname(),
            $name->secondSurname(),
        ])));

        if ($user !== null && $this->dedicatedProfile->isExactLegacyAdmin($user)) {
            return $this->state($personId, $personName, InstitutionalReportingAccessState::EXACT_ADMIN, $user);
        }
        if ($representative !== null && $student !== null) {
            return $this->state($personId, $personName, InstitutionalReportingAccessState::INELIGIBLE_MULTIPLE_ROLES, $user);
        }
        if ($representative !== null) {
            return $this->state($personId, $personName, InstitutionalReportingAccessState::INELIGIBLE_REPRESENTATIVE, $user);
        }
        if ($student !== null) {
            return $this->state($personId, $personName, InstitutionalReportingAccessState::INELIGIBLE_STUDENT, $user);
        }
        if ($user === null) {
            return new InstitutionalReportingAccessState(
                $personId,
                $personName,
                InstitutionalReportingAccessState::ELIGIBLE_NO_USER,
            );
        }
        if ($this->dedicatedProfile->handle($user)) {
            return $this->state(
                $personId,
                $personName,
                $user->status() === UserStatus::Active
                    ? InstitutionalReportingAccessState::ACTIVE_DEDICATED
                    : InstitutionalReportingAccessState::DISABLED_DEDICATED,
                $user,
            );
        }
        if ($user->status() === UserStatus::Active) {
            return $this->state($personId, $personName, InstitutionalReportingAccessState::ELIGIBLE_ACTIVE_USER, $user);
        }

        return $this->state($personId, $personName, InstitutionalReportingAccessState::INCOHERENT, $user);
    }

    private function state(
        int $personId,
        string $personName,
        string $state,
        ?\App\IdentityAccess\Domain\User $user,
    ): InstitutionalReportingAccessState {
        return new InstitutionalReportingAccessState(
            $personId,
            $personName,
            $state,
            $user?->loginIdentifier()->value(),
            $user?->status(),
        );
    }
}
