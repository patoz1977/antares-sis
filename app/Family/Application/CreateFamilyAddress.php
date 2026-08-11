<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\CreateFamilyAddressInput;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class CreateFamilyAddress
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(CreateFamilyAddressInput $input): FamilyAddressOutput
    {
        $familyId = new FamilyId($input->familyId);
        $family = FamilyResourcesApplicationSupport::load($this->families, $familyId);
        $knownIds = FamilyResourcesApplicationSupport::persistedIds($family->addresses());
        $label = new AddressLabel($input->label);
        $address = FamilyResourcesApplicationSupport::address($input);
        $family->addAddress($label, $address);
        $output = FamilyResourcesApplicationSupport::save($this->families, $family, $familyId);

        foreach ($output->addresses as $resource) {
            if (!in_array($resource->id, $knownIds, true)
                && FamilyResourcesApplicationSupport::addressMatches($resource, $label, $address)
                && $resource->status === 'ACTIVE'
            ) {
                return $resource;
            }
        }

        throw new InvalidPersistedFamilyResult(
            'Family repository did not return the created Address with persisted identity.'
        );
    }

}
