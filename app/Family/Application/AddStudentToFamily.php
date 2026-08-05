<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AddStudentToFamilyInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\StudentAlreadyHasActiveFamily;
use App\Family\Application\Exception\StudentNotFoundForFamily;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentReference;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\StudentId;

final readonly class AddStudentToFamily
{
    public function __construct(
        private FamilyRepository $families,
        private StudentRepository $students,
    ) {
    }

    public function handle(AddStudentToFamilyInput $input): FamilyOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = $this->families->findById($familyId);
        if ($family === null) {
            throw new FamilyNotFound('Family was not found.');
        }

        $studentId = new StudentId($input->studentId);
        if ($this->students->findById($studentId) === null) {
            throw new StudentNotFoundForFamily('Student for Family was not found.');
        }

        $familyStudentId = new FamilyStudentReference($input->studentId);
        if ($this->families->findActiveByStudentId($familyStudentId) !== null) {
            throw new StudentAlreadyHasActiveFamily(
                'Student already has an active Family membership.'
            );
        }

        $family->addStudent($familyStudentId, $input->startedAt);
        $persisted = $this->families->save($family);
        $this->assertPersistedMembership($persisted, $familyId, $familyStudentId);

        return FamilyOutput::fromFamily($persisted, $familyId);
    }

    private function assertPersistedMembership(
        Family $persisted,
        FamilyId $expectedFamilyId,
        FamilyStudentReference $studentId,
    ): void {
        if ($persisted->id()?->equals($expectedFamilyId) !== true) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an invalid persisted Family identity.'
            );
        }

        foreach ($persisted->activeStudents() as $membership) {
            if ($membership->studentId()->equals($studentId) && $membership->id() !== null) {
                return;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the persisted Student membership identity.'
        );
    }
}
