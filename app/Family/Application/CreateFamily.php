<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Dto\CreateFamilyWithCodeInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyCodeGenerationFailed;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\RepresentativeNotFoundForFamily;
use App\Family\Domain\Exception\FamilyCodeAlreadyExists;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyCode;
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
        $this->assertReferences(
            $input->initialRepresentativeId,
            $input->initialRelationshipTypeId,
        );
        $displayName = new DisplayName($input->displayName);
        for ($attempt = 1; $attempt <= self::MAX_CODE_ATTEMPTS; $attempt++) {
            $familyCode = $this->familyCodes->generate();
            if ($this->families->findByCode($familyCode) !== null) {
                continue;
            }

            try {
                return $this->persist(
                    $familyCode,
                    $displayName,
                    $input->status,
                    $input->initialRepresentativeId,
                    $input->initialRelationshipTypeId,
                    $input->startedAt,
                );
            } catch (FamilyCodeAlreadyExists) {
                continue;
            }
        }

        throw new FamilyCodeGenerationFailed('FamilyCode could not be allocated after five attempts.');
    }

    public function handleWithCode(CreateFamilyWithCodeInput $input): FamilyOutput
    {
        $this->assertReferences(
            $input->initialRepresentativeId,
            $input->initialRelationshipTypeId,
        );
        $familyCode = new FamilyCode($input->familyCode);
        if ($this->families->findByCode($familyCode) !== null) {
            throw new FamilyCodeAlreadyExists('FamilyCode is already in use.');
        }

        return $this->persist(
            $familyCode,
            new DisplayName($input->displayName),
            $input->status,
            $input->initialRepresentativeId,
            $input->initialRelationshipTypeId,
            $input->startedAt,
        );
    }

    private function assertReferences(
        int $initialRepresentativeId,
        int $initialRelationshipTypeId,
    ): void {
        $representativeId = new RepresentativeId($initialRepresentativeId);
        if ($this->representatives->findById($representativeId) === null) {
            throw new RepresentativeNotFoundForFamily(
                'Initial Representative for Family was not found.'
            );
        }

        if (!$this->relationshipTypes->exists($initialRelationshipTypeId)) {
            throw new RelationshipTypeNotFound('Relationship type for Family was not found.');
        }
    }

    private function persist(
        FamilyCode $familyCode,
        DisplayName $displayName,
        \App\Family\Domain\FamilyStatus $status,
        int $initialRepresentativeId,
        int $initialRelationshipTypeId,
        \DateTimeImmutable $startedAt,
    ): FamilyOutput {
        $familyRepresentativeId = new FamilyRepresentativeReference(
            $initialRepresentativeId
        );
        $relationshipTypeId = new RelationshipTypeId($initialRelationshipTypeId);
        $persisted = $this->families->save(Family::create(
            $familyCode,
            $displayName,
            $status,
            $familyRepresentativeId,
            $relationshipTypeId,
            $startedAt,
        ));
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
