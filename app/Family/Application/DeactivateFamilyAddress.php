<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\DeactivateFamilyAddressInput;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class DeactivateFamilyAddress
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(DeactivateFamilyAddressInput $input): FamilyAddressOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $family->deactivateAddress(new FamilyAddressId($input->familyAddressId));
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->addresses as $resource) {
            if ($resource->id === $input->familyAddressId && $resource->status === 'INACTIVE') {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult('Family repository did not return the deactivated Address state.');
    }
}
