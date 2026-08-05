<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\AddRepresentativeToFamilyInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\RepresentativeNotFoundForFamily;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class AddRepresentativeToFamily
{
    public function __construct(
        private FamilyRepository $families,
        private RepresentativeRepository $representatives,
        private RelationshipTypeLookup $relationshipTypes,
    ) {
    }

    public function handle(AddRepresentativeToFamilyInput $input): FamilyOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = $this->families->findById($familyId);
        if ($family === null) {
            throw new FamilyNotFound('Family was not found.');
        }

        $representativeId = new RepresentativeId($input->representativeId);
        if ($this->representatives->findById($representativeId) === null) {
            throw new RepresentativeNotFoundForFamily('Representative for Family was not found.');
        }

        if (!$this->relationshipTypes->exists($input->relationshipTypeId)) {
            throw new RelationshipTypeNotFound('Relationship type for Family was not found.');
        }

        $familyRepresentativeId = new FamilyRepresentativeReference($input->representativeId);
        $relationshipTypeId = new RelationshipTypeId($input->relationshipTypeId);
        $family->addRepresentative(
            $familyRepresentativeId,
            $relationshipTypeId,
            $input->startedAt,
        );

        $persisted = $this->families->save($family);
        $this->assertPersistedMembership(
            $persisted,
            $familyId,
            $familyRepresentativeId,
            $relationshipTypeId,
        );

        return FamilyOutput::fromFamily($persisted, $familyId);
    }

    private function assertPersistedMembership(
        Family $persisted,
        FamilyId $expectedFamilyId,
        FamilyRepresentativeReference $representativeId,
        RelationshipTypeId $relationshipTypeId,
    ): void {
        if ($persisted->id()?->equals($expectedFamilyId) !== true) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an invalid persisted Family identity.'
            );
        }

        foreach ($persisted->activeRepresentatives() as $membership) {
            if ($membership->representativeId()->equals($representativeId)
                && $membership->relationshipTypeId()->equals($relationshipTypeId)
                && !$membership->isPrimary()
                && $membership->id() !== null
            ) {
                return;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the persisted Representative membership identity.'
        );
    }
}
