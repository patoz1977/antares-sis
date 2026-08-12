<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\EndRepresentativeAddressAssignmentInput;
use App\Family\Application\Dto\RepresentativeAddressAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;

final readonly class EndRepresentativeAddressAssignment
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(EndRepresentativeAddressAssignmentInput $input): RepresentativeAddressAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $assignmentId = null;
        foreach ($family->representativeAddressAssignments() as $assignment) {
            if ($assignment->isActive()
                && $assignment->representativeId()->value() === $input->representativeId
            ) {
                $assignmentId = $assignment->id()?->value();
                break;
            }
        }
        $family->endRepresentativeAddressAssignment(
            new RepresentativeId($input->representativeId),
            $input->endedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->representativeAddressAssignments as $assignment) {
            if ($assignment->id === $assignmentId
                && !$assignment->isActive
                && $assignment->endedAt == FamilyResourcesApplicationSupport::secondPrecision($input->endedAt)
            ) {
                return $assignment;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the ended Representative address assignment state.'
        );
    }
}
