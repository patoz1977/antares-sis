<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\DeactivateFamilyEmergencyContactInput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class DeactivateFamilyEmergencyContact
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(DeactivateFamilyEmergencyContactInput $input): FamilyEmergencyContactOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $family->deactivateEmergencyContact(new FamilyEmergencyContactId($input->familyEmergencyContactId));
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->emergencyContacts as $resource) {
            if ($resource->id === $input->familyEmergencyContactId && $resource->status === 'INACTIVE') {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the deactivated Emergency Contact state.'
        );
    }
}
