<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\DeactivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class DeactivateFamilyAuthorizedPickup
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(DeactivateFamilyAuthorizedPickupInput $input): FamilyAuthorizedPickupOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $family->deactivateAuthorizedPickup(new FamilyAuthorizedPickupId($input->familyAuthorizedPickupId));
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->authorizedPickups as $resource) {
            if ($resource->id === $input->familyAuthorizedPickupId && $resource->status === 'INACTIVE') {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the deactivated Authorized Pickup state.'
        );
    }
}
