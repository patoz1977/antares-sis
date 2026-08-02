<?php

declare(strict_types=1);

namespace App\Student\Application;

use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;
use App\Student\Application\Dto\CreateStudentInput;
use App\Student\Application\Dto\StudentOutput;
use App\Student\Application\Exception\InstitutionalCodeAlreadyUsed;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Application\Exception\StudentAlreadyExistsForPerson;
use App\Student\Application\Exception\StudentPersonNotFound;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use DateTimeImmutable;

final readonly class CreateStudent
{
    public function __construct(
        private PersonRepository $persons,
        private StudentRepository $students,
    ) {
    }

    public function handle(CreateStudentInput $input, DateTimeImmutable $today): StudentOutput
    {
        $personDomainId = new PersonDomainId($input->personId);
        $institutionalCode = new InstitutionalCode($input->institutionalCode);
        $admissionDate = new AdmissionDate($input->admissionDate, $today->setTime(0, 0));

        if ($this->persons->findById($personDomainId) === null) {
            throw new StudentPersonNotFound('Person for Student was not found.');
        }

        $personId = new PersonId($input->personId);
        if ($this->students->findByPersonId($personId) !== null) {
            throw new StudentAlreadyExistsForPerson('Person already has a Student role.');
        }

        if ($this->students->findByInstitutionalCode($institutionalCode) !== null) {
            throw new InstitutionalCodeAlreadyUsed('Institutional code is already in use.');
        }

        $student = new Student(
            null,
            $personId,
            $institutionalCode,
            $admissionDate,
            $input->status,
        );

        $persisted = $this->students->save($student);
        $id = $persisted->id();
        if ($id === null) {
            throw new InvalidPersistedStudentResult(
                'Student repository returned an invalid persisted identity.'
            );
        }

        return StudentOutput::fromStudent($persisted, $id);
    }
}
