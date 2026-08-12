<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AuthorizedPickupAssignmentOutput;
use App\Family\Application\Dto\EndAuthorizedPickupAssignmentInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class EndAuthorizedPickupAssignment
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(EndAuthorizedPickupAssignmentInput $input): AuthorizedPickupAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $assignmentId = null;
        foreach ($family->authorizedPickupAssignments() as $assignment) {
            if ($assignment->isActive()
                && $assignment->studentId()->value() === $input->studentId
                && $assignment->familyAuthorizedPickupId()->value() === $input->familyAuthorizedPickupId
            ) {
                $assignmentId = $assignment->id()?->value();
                break;
            }
        }
        $family->endAuthorizedPickupAssignment(
            new StudentId($input->studentId),
            new FamilyAuthorizedPickupId($input->familyAuthorizedPickupId),
            $input->endedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->authorizedPickupAssignments as $assignment) {
            if ($assignment->id === $assignmentId
                && !$assignment->isActive
                && $assignment->endedAt == FamilyResourcesApplicationSupport::secondPrecision($input->endedAt)
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the ended Authorized Pickup assignment state.'
        );
    }
}
