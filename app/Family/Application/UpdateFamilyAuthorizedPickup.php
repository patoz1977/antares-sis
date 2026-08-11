<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\UpdateFamilyAuthorizedPickupInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\RelationshipTypeId;

final readonly class UpdateFamilyAuthorizedPickup
{
    public function __construct(
        private FamilyRepository $families,
        private RelationshipTypeLookup $relationshipTypes,
        private DocumentTypeLookup $documentTypes,
    ) {
    }

    public function handle(UpdateFamilyAuthorizedPickupInput $input): FamilyAuthorizedPickupOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        CreateFamilyAuthorizedPickup::assertReferences(
            $this->relationshipTypes,
            $this->documentTypes,
            $input,
        );
        $names = new FamilyResourceName($input->names);
        $relationshipTypeId = new RelationshipTypeId($input->relationshipTypeId);
        $information = FamilyResourcesApplicationSupport::pickupInformation($input);
        $identification = FamilyResourcesApplicationSupport::pickupIdentification($input);
        $family->updateAuthorizedPickup(
            new FamilyAuthorizedPickupId($input->familyAuthorizedPickupId),
            $names,
            $relationshipTypeId,
            $information,
            $identification,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->authorizedPickups as $resource) {
            if ($resource->id === $input->familyAuthorizedPickupId
                && FamilyResourcesApplicationSupport::authorizedPickupMatches(
                    $resource,
                    $names,
                    $relationshipTypeId,
                    $information,
                    $identification,
                )
            ) {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the updated Authorized Pickup state.'
        );
    }
}
