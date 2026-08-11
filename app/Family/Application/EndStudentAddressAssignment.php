<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\EndStudentAddressAssignmentInput;
use App\Family\Application\Dto\StudentAddressAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class EndStudentAddressAssignment
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(EndStudentAddressAssignmentInput $input): StudentAddressAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $assignmentId = null;
        foreach ($family->studentAddressAssignments() as $assignment) {
            if ($assignment->isActive() && $assignment->studentId()->value() === $input->studentId) {
                $assignmentId = $assignment->id()?->value();
                break;
            }
        }
        $family->endStudentAddressAssignment(new StudentId($input->studentId), $input->endedAt);
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->studentAddressAssignments as $assignment) {
            if ($assignment->id === $assignmentId
                && !$assignment->isActive
                && $assignment->endedAt == FamilyResourcesApplicationSupport::secondPrecision($input->endedAt)
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the ended Student address assignment state.'
        );
    }
}
