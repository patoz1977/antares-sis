<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AssignEmergencyContactInput;
use App\Family\Application\Dto\EmergencyContactAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class AssignEmergencyContact
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(AssignEmergencyContactInput $input): EmergencyContactAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds($family->emergencyContactAssignments());
        $family->assignEmergencyContactToStudent(
            new StudentId($input->studentId),
            new FamilyEmergencyContactId($input->familyEmergencyContactId),
            $input->priority === null ? null : new EmergencyContactPriority($input->priority),
            $input->startedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->emergencyContactAssignments as $assignment) {
            if (!in_array($assignment->id, $knownIds, true)
                && $assignment->studentId === $input->studentId
                && $assignment->familyEmergencyContactId === $input->familyEmergencyContactId
                && $assignment->priority === $input->priority
                && $assignment->startedAt == FamilyResourcesApplicationSupport::secondPrecision($input->startedAt)
                && $assignment->isActive
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the requested Emergency Contact assignment state.'
        );
    }
}
