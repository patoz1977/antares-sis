<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration;

use App\Family\Application\AddStudentToFamily;
use App\Family\Application\Dto\AddStudentToFamilyInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Orchestration\Dto\AttachExistingStudentToFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentForExistingPersonInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Application\Orchestration\Dto\StudentFamilyOutput;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Person\Application\GetPerson;
use App\Student\Application\CreateStudent;
use App\Student\Application\Dto\CreateStudentInput;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Application\GetStudent;
use DateTimeImmutable;

/**
 * Transaction-aware composition primitive. Its caller owns the outer transaction.
 */
final readonly class StudentFamilyCoordinator
{
    public function __construct(
        private CreatePerson $createPerson,
        private GetPerson $getPerson,
        private CreateStudent $createStudent,
        private GetStudent $getStudent,
        private AddStudentToFamily $addStudentToFamily,
    ) {
    }

    public function createWithNewPerson(
        CreateStudentInFamilyInput $input,
        DateTimeImmutable $today,
    ): StudentFamilyOutput {
        $person = $this->createPerson->handle(new CreatePersonInput(
            $input->firstName,
            $input->middleName,
            $input->firstSurname,
            $input->secondSurname,
            $input->documentTypeId,
            $input->documentNumber,
            $input->birthDate,
            $input->sexId,
            $input->maritalStatusId,
            $input->educationLevelId,
            $input->email,
            $input->mobilePhone,
            $input->landlinePhone,
            $input->personStatus,
        ), $today);
        if ($person->id <= 0) {
            throw new InvalidPersistedPersonResult(
                'Composite operation received an invalid persisted Person identity.'
            );
        }

        return $this->createForPerson(
            new CreateStudentForExistingPersonInput(
                $input->familyId,
                $person->id,
                $input->institutionalCode,
                $input->admissionDate,
                $input->studentStatus,
                $input->startedAt,
            ),
            $today,
        );
    }

    public function createForPerson(
        CreateStudentForExistingPersonInput $input,
        DateTimeImmutable $today,
    ): StudentFamilyOutput {
        $person = $this->getPerson->handle($input->personId);
        $student = $this->createStudent->handle(new CreateStudentInput(
            $person->id,
            $input->institutionalCode,
            $input->admissionDate,
            $input->studentStatus,
        ), $today);
        if ($student->id <= 0) {
            throw new InvalidPersistedStudentResult(
                'Composite operation received an invalid persisted Student identity.'
            );
        }

        return $this->attach(new AttachExistingStudentToFamilyInput(
            $input->familyId,
            $student->id,
            $input->startedAt,
        ));
    }

    public function attach(AttachExistingStudentToFamilyInput $input): StudentFamilyOutput
    {
        $student = $this->getStudent->handle($input->studentId);
        $person = $this->getPerson->handle($student->personId);
        $family = $this->addStudentToFamily->handle(new AddStudentToFamilyInput(
            $input->familyId,
            $student->id,
            $input->startedAt,
        ));
        if ($family->id <= 0) {
            throw new InvalidPersistedFamilyResult(
                'Composite operation received an invalid persisted Family identity.'
            );
        }

        return new StudentFamilyOutput($person, $student, $family);
    }
}
