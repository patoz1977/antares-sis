<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;

final readonly class InstitutionalReportingPersonEligibility
{
    public function __construct(
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
        private StudentRepository $students,
    ) {
    }

    public function lockEligible(int $personId): Person
    {
        $person = $this->persons->findByIdForUpdate(new PersonId($personId));
        if ($person === null) {
            throw new InstitutionalReportingAccessConflict('Institutional reporting Person was not found.');
        }

        if ($this->representatives->findByPersonId(new RepresentativePersonId($personId)) !== null
            || $this->students->findByPersonId(new StudentPersonId($personId)) !== null
        ) {
            throw new InstitutionalReportingAccessConflict('Person is not eligible for institutional reporting access.');
        }

        return $person;
    }
}
