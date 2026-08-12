<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AssignRepresentativeAddressInput;
use App\Family\Application\Dto\RepresentativeAddressAssignmentOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;

final readonly class AssignRepresentativeAddress
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(AssignRepresentativeAddressInput $input): RepresentativeAddressAssignmentOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds(
            $family->representativeAddressAssignments()
        );
        $family->assignAddressToRepresentative(
            new RepresentativeId($input->representativeId),
            new FamilyAddressId($input->familyAddressId),
            $input->startedAt,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        $match = null;
        $activeForRepresentative = 0;
        foreach ($output->representativeAddressAssignments as $assignment) {
            if ($assignment->representativeId === $input->representativeId && $assignment->isActive) {
                $activeForRepresentative++;
            }
            if (!in_array($assignment->id, $knownIds, true)
                && $assignment->representativeId === $input->representativeId
                && $assignment->familyAddressId === $input->familyAddressId
                && $assignment->startedAt == FamilyResourcesApplicationSupport::secondPrecision($input->startedAt)
                && $assignment->isActive
            ) {
                $match = $assignment;
            }
        }
        if ($match !== null && $activeForRepresentative === 1) {
            return $match;
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the requested Representative address assignment state.'
        );
    }

}
