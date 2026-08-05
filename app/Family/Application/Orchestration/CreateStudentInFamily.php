<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration;

use App\Family\Application\AddStudentToFamily;
use App\Family\Application\Dto\AddStudentToFamilyInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Application\Orchestration\Dto\StudentFamilyOutput;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Student\Application\CreateStudent;
use App\Student\Application\Dto\CreateStudentInput;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use Core\Application\TransactionRunner;
use DateTimeImmutable;

final readonly class CreateStudentInFamily
{
    public function __construct(
        private TransactionRunner $transactions,
        private GetFamily $getFamily,
        private CreatePerson $createPerson,
        private CreateStudent $createStudent,
        private AddStudentToFamily $addStudentToFamily,
    ) {
    }

    public function handle(
        CreateStudentInFamilyInput $input,
        DateTimeImmutable $today,
    ): StudentFamilyOutput {
        return $this->transactions->run(function () use ($input, $today): StudentFamilyOutput {
            $this->getFamily->handle($input->familyId);

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
        });
    }
}
