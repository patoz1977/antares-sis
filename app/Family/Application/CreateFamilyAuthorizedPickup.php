<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\CreateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\UpdateFamilyAuthorizedPickupInput;
use App\Family\Application\Exception\DocumentTypeNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\RelationshipTypeId;

final readonly class CreateFamilyAuthorizedPickup
{
    public function __construct(
        private FamilyRepository $families,
        private RelationshipTypeLookup $relationshipTypes,
        private DocumentTypeLookup $documentTypes,
    ) {
    }

    public function handle(CreateFamilyAuthorizedPickupInput $input): FamilyAuthorizedPickupOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        self::assertReferences($this->relationshipTypes, $this->documentTypes, $input);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds($family->authorizedPickups());
        $names = new FamilyResourceName($input->names);
        $relationshipTypeId = new RelationshipTypeId($input->relationshipTypeId);
        $information = FamilyResourcesApplicationSupport::pickupInformation($input);
        $identification = FamilyResourcesApplicationSupport::pickupIdentification($input);
        $family->addAuthorizedPickup($names, $relationshipTypeId, $information, $identification);
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->authorizedPickups as $resource) {
            if (!in_array($resource->id, $knownIds, true)
                && FamilyResourcesApplicationSupport::authorizedPickupMatches(
                    $resource,
                    $names,
                    $relationshipTypeId,
                    $information,
                    $identification,
                )
                && $resource->status === 'ACTIVE'
            ) {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the created Authorized Pickup with persisted identity.'
        );
    }

    public static function assertReferences(
        RelationshipTypeLookup $relationshipTypes,
        DocumentTypeLookup $documentTypes,
        CreateFamilyAuthorizedPickupInput|UpdateFamilyAuthorizedPickupInput $input,
    ): void {
        if (!$relationshipTypes->exists($input->relationshipTypeId)) {
            throw new RelationshipTypeNotFound('Relationship type for Family resource was not found.');
        }
        if ($input->documentTypeId !== null && !$documentTypes->exists($input->documentTypeId)) {
            throw new DocumentTypeNotFound('Document type for Authorized Pickup was not found.');
        }
    }

}
