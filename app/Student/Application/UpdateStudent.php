<?php

declare(strict_types=1);

namespace App\Student\Application;

use App\Student\Application\Dto\StudentOutput;
use App\Student\Application\Dto\UpdateStudentInput;
use App\Student\Application\Exception\InstitutionalCodeAlreadyUsed;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Application\Exception\StudentNotFound;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final readonly class UpdateStudent
{
    public function __construct(private StudentRepository $students)
    {
    }

    public function handle(UpdateStudentInput $input, DateTimeImmutable $today): StudentOutput
    {
        $id = new StudentId($input->studentId);
        $student = $this->students->findById($id);
        if ($student === null) {
            throw new StudentNotFound('Student was not found.');
        }

        $institutionalCode = new InstitutionalCode($input->institutionalCode);
        $admissionDate = new AdmissionDate($input->admissionDate, $today->setTime(0, 0));
        $owner = $this->students->findByInstitutionalCode($institutionalCode);
        if ($owner !== null && $owner->id()?->equals($id) !== true) {
            throw new InstitutionalCodeAlreadyUsed('Institutional code is already in use.');
        }

        $student->updateAcademicInformation($institutionalCode, $admissionDate);
        match ($input->status) {
            StudentStatus::Active => $student->activate(),
            StudentStatus::Inactive => $student->deactivate(),
        };

        $persisted = $this->students->save($student);
        $persistedId = $persisted->id();
        if ($persistedId === null || !$persistedId->equals($id)) {
            throw new InvalidPersistedStudentResult(
                'Student repository returned an invalid persisted identity.'
            );
        }

        return StudentOutput::fromStudent($persisted, $persistedId);
    }
}
