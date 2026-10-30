<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\Person\Domain\Person;
use App\Person\Application\LockingPersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\LockingRepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Student\Application\LockingStudentRepository;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;

final readonly class InstitutionalReportingPersonEligibility
{
    public function __construct(
        private LockingPersonRepository $persons,
        private LockingRepresentativeRepository $representatives,
        private LockingStudentRepository $students,
    ) {
    }

    public function lockEligible(int $personId): Person
    {
        $person = $this->persons->findByIdForUpdate(new PersonId($personId));
        if ($person === null) {
            throw new InstitutionalReportingAccessConflict('Institutional reporting Person was not found.');
        }

        if ($this->representatives->findByPersonIdForUpdate(
            new RepresentativePersonId($personId)
        ) !== null
            || $this->students->findByPersonIdForUpdate(new StudentPersonId($personId)) !== null
        ) {
            throw new InstitutionalReportingAccessConflict('Person is not eligible for institutional reporting access.');
        }

        return $person;
    }
}
