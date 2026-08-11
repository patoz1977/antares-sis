<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\UpdateFamilyAddressInput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class UpdateFamilyAddress
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(UpdateFamilyAddressInput $input): FamilyAddressOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $label = new AddressLabel($input->label);
        $address = FamilyResourcesApplicationSupport::address($input);
        $family->updateAddress(new FamilyAddressId($input->familyAddressId), $label, $address);
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->addresses as $resource) {
            if ($resource->id === $input->familyAddressId
                && FamilyResourcesApplicationSupport::addressMatches($resource, $label, $address)
            ) {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult('Family repository did not return the updated Address state.');
    }
}
