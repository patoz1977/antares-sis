<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\EmergencyContactAssignmentOutput;
use App\Family\Application\Dto\EndEmergencyContactAssignmentInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class EndEmergencyContactAssignment
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(EndEmergencyContactAssignmentInput $input): EmergencyContactAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $assignmentId = null;
        foreach ($family->emergencyContactAssignments() as $assignment) {
            if ($assignment->isActive()
                && $assignment->studentId()->value() === $input->studentId
                && $assignment->familyEmergencyContactId()->value() === $input->familyEmergencyContactId
            ) {
                $assignmentId = $assignment->id()?->value();
                break;
            }
        }
        $family->endEmergencyContactAssignment(
            new StudentId($input->studentId),
            new FamilyEmergencyContactId($input->familyEmergencyContactId),
            $input->endedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->emergencyContactAssignments as $assignment) {
            if ($assignment->id === $assignmentId
                && !$assignment->isActive
                && $assignment->endedAt == FamilyResourcesApplicationSupport::secondPrecision($input->endedAt)
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the ended Emergency Contact assignment state.'
        );
    }
}
