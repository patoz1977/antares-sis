<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Dto\UpdateFamilyEmergencyContactInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\RelationshipTypeId;

final readonly class UpdateFamilyEmergencyContact
{
    public function __construct(
        private FamilyRepository $families,
        private RelationshipTypeLookup $relationshipTypes,
    ) {
    }

    public function handle(UpdateFamilyEmergencyContactInput $input): FamilyEmergencyContactOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        if (!$this->relationshipTypes->exists($input->relationshipTypeId)) {
            throw new RelationshipTypeNotFound('Relationship type for Family resource was not found.');
        }
        $names = new FamilyResourceName($input->names);
        $relationshipTypeId = new RelationshipTypeId($input->relationshipTypeId);
        $information = FamilyResourcesApplicationSupport::emergencyInformation($input);
        $family->updateEmergencyContact(
            new FamilyEmergencyContactId($input->familyEmergencyContactId),
            $names,
            $relationshipTypeId,
            $information,
        );
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->emergencyContacts as $resource) {
            if ($resource->id === $input->familyEmergencyContactId
                && FamilyResourcesApplicationSupport::emergencyContactMatches(
                    $resource,
                    $names,
                    $relationshipTypeId,
                    $information,
                )
            ) {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the updated Emergency Contact state.'
        );
    }
}
