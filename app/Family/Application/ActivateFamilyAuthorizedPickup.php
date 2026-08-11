<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\ActivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class ActivateFamilyAuthorizedPickup
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(ActivateFamilyAuthorizedPickupInput $input): FamilyAuthorizedPickupOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $family->activateAuthorizedPickup(new FamilyAuthorizedPickupId($input->familyAuthorizedPickupId));
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);
        foreach ($output->authorizedPickups as $resource) {
            if ($resource->id === $input->familyAuthorizedPickupId && $resource->status === 'ACTIVE') {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the activated Authorized Pickup state.'
        );
    }
}
