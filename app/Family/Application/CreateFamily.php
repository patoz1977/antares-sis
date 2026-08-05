<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\RepresentativeNotFoundForFamily;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class CreateFamily
{
    public function __construct(
        private FamilyRepository $families,
        private RepresentativeRepository $representatives,
        private RelationshipTypeLookup $relationshipTypes,
    ) {
    }

    public function handle(CreateFamilyInput $input): FamilyOutput
    {
        $displayName = new DisplayName($input->displayName);
        $representativeId = new RepresentativeId($input->initialRepresentativeId);
        if ($this->representatives->findById($representativeId) === null) {
            throw new RepresentativeNotFoundForFamily(
                'Initial Representative for Family was not found.'
            );
        }

        if (!$this->relationshipTypes->exists($input->initialRelationshipTypeId)) {
            throw new RelationshipTypeNotFound('Relationship type for Family was not found.');
        }

        $familyRepresentativeId = new FamilyRepresentativeReference(
            $input->initialRepresentativeId
        );
        $relationshipTypeId = new RelationshipTypeId($input->initialRelationshipTypeId);
        $family = Family::create(
            $displayName,
            $input->status,
            $familyRepresentativeId,
            $relationshipTypeId,
            $input->startedAt,
        );

        $persisted = $this->families->save($family);
        $persistedId = $persisted->id();
        if ($persistedId === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned a new Family without persisted identity.'
            );
        }

        $primary = $persisted->primaryRepresentative();
        if ($primary->id() === null
            || !$primary->representativeId()->equals($familyRepresentativeId)
        ) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an invalid persisted primary Representative membership.'
            );
        }

        return FamilyOutput::fromFamily($persisted, $persistedId);
    }
}
