<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AssignAuthorizedPickupInput;
use App\Family\Application\Dto\AuthorizedPickupAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId;

final readonly class AssignAuthorizedPickup
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(AssignAuthorizedPickupInput $input): AuthorizedPickupAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds($family->authorizedPickupAssignments());
        $family->assignAuthorizedPickupToStudent(
            new StudentId($input->studentId),
            new FamilyAuthorizedPickupId($input->familyAuthorizedPickupId),
            $input->startedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->authorizedPickupAssignments as $assignment) {
            if (!in_array($assignment->id, $knownIds, true)
                && $assignment->studentId === $input->studentId
                && $assignment->familyAuthorizedPickupId === $input->familyAuthorizedPickupId
                && $assignment->startedAt == FamilyResourcesApplicationSupport::secondPrecision($input->startedAt)
                && $assignment->isActive
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the requested Authorized Pickup assignment state.'
        );
    }
}
