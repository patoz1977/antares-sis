<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AssignStudentAddressInput;
use App\Family\Application\Dto\StudentAddressAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class AssignStudentAddress
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(AssignStudentAddressInput $input): StudentAddressAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds($family->studentAddressAssignments());
        $family->assignAddressToStudent(
            new StudentId($input->studentId),
            new FamilyAddressId($input->familyAddressId),
            $input->startedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        $match = null;
        $activeForStudent = 0;
        foreach ($output->studentAddressAssignments as $assignment) {
            if ($assignment->studentId === $input->studentId && $assignment->isActive) {
                $activeForStudent++;
            }
            if (!in_array($assignment->id, $knownIds, true)
                && $assignment->studentId === $input->studentId
                && $assignment->familyAddressId === $input->familyAddressId
                && $assignment->startedAt == FamilyResourcesApplicationSupport::secondPrecision($input->startedAt)
                && $assignment->isActive
            ) {
                $match = $assignment;
            }
        }
        if ($match !== null && $activeForStudent === 1) {
            return $match;
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the requested Student address assignment state.'
        );
    }
}
