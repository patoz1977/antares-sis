<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyCodeGenerationFailed;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\RepresentativeNotFoundForFamily;
use App\Family\Domain\Exception\FamilyCodeAlreadyExists;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class CreateFamily
{
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private FamilyRepository $families,
        private RepresentativeRepository $representatives,
        private RelationshipTypeLookup $relationshipTypes,
        private FamilyCodeGenerator $familyCodes,
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
        $persisted = null;
        for ($attempt = 1; $attempt <= self::MAX_CODE_ATTEMPTS; $attempt++) {
            $familyCode = $this->familyCodes->generate();
            if ($this->families->findByCode($familyCode) !== null) {
                continue;
            }

            $family = Family::create(
                $familyCode,
                $displayName,
                $input->status,
                $familyRepresentativeId,
                $relationshipTypeId,
                $input->startedAt,
            );

            try {
                $persisted = $this->families->save($family);
                break;
            } catch (FamilyCodeAlreadyExists) {
                continue;
            }
        }
        if ($persisted === null) {
            throw new FamilyCodeGenerationFailed('FamilyCode could not be allocated after five attempts.');
        }
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
