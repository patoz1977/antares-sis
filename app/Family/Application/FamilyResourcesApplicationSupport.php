<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Application\Dto\CreateFamilyAddressInput;
use App\Family\Application\Dto\CreateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\CreateFamilyEmergencyContactInput;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Dto\UpdateFamilyAddressInput;
use App\Family\Application\Dto\UpdateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\UpdateFamilyEmergencyContactInput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DocumentTypeId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\Geolocation;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use DateTimeImmutable;

final class FamilyResourcesApplicationSupport
{
    public static function load(FamilyRepository $families, FamilyId $familyId): Family
    {
        $family = $families->findById($familyId);
        if ($family === null) {
            throw new FamilyNotFound('Family was not found.');
        }

        return $family;
    }

    public static function save(
        FamilyRepository $families,
        Family $family,
        FamilyId $familyId,
    ): FamilyResourcesOutput {
        return FamilyResourcesOutput::fromFamily($families->save($family), $familyId);
    }

    /** @param list<object> $entities @return list<int> */
    public static function persistedIds(array $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $ids[] = $id->value();
            }
        }

        return $ids;
    }

    public static function address(CreateFamilyAddressInput|UpdateFamilyAddressInput $input): Address
    {
        $latitudeAbsent = $input->latitude === null || trim($input->latitude) === '';
        $longitudeAbsent = $input->longitude === null || trim($input->longitude) === '';
        $geolocation = $latitudeAbsent && $longitudeAbsent
            ? null
            : new Geolocation($input->latitude ?? '', $input->longitude ?? '');

        return new Address(
            $input->mainStreet,
            $input->streetNumber,
            $input->secondaryStreet,
            $input->sector,
            $input->reference,
            $geolocation,
        );
    }

    public static function addressMatches(
        FamilyAddressOutput $output,
        AddressLabel $label,
        Address $address,
    ): bool {
        $geolocation = $address->geolocation();

        return $output->label === $label->value()
            && $output->mainStreet === $address->mainStreet()
            && $output->streetNumber === $address->streetNumber()
            && $output->secondaryStreet === $address->secondaryStreet()
            && $output->sector === $address->sector()
            && $output->reference === $address->reference()
            && $output->latitude === $geolocation?->latitude()
            && $output->longitude === $geolocation?->longitude();
    }

    public static function emergencyInformation(
        CreateFamilyEmergencyContactInput|UpdateFamilyEmergencyContactInput $input,
    ): EmergencyContactInformation {
        return new EmergencyContactInformation(
            $input->mobilePhone,
            $input->phone,
            $input->email,
            $input->observations,
        );
    }

    public static function emergencyContactMatches(
        FamilyEmergencyContactOutput $output,
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        EmergencyContactInformation $information,
    ): bool {
        return $output->names === $names->value()
            && $output->relationshipTypeId === $relationshipTypeId->value()
            && $output->mobilePhone === $information->mobilePhone()
            && $output->phone === $information->phone()
            && $output->email === $information->email()
            && $output->observations === $information->observations();
    }

    public static function pickupInformation(
        CreateFamilyAuthorizedPickupInput|UpdateFamilyAuthorizedPickupInput $input,
    ): AuthorizedPickupInformation {
        return new AuthorizedPickupInformation($input->mobilePhone, $input->phone, $input->observations);
    }

    public static function pickupIdentification(
        CreateFamilyAuthorizedPickupInput|UpdateFamilyAuthorizedPickupInput $input,
    ): ?PickupIdentification {
        return PickupIdentification::fromPair(
            $input->documentTypeId === null ? null : new DocumentTypeId($input->documentTypeId),
            $input->documentNumber,
        );
    }

    public static function authorizedPickupMatches(
        FamilyAuthorizedPickupOutput $output,
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        AuthorizedPickupInformation $information,
        ?PickupIdentification $identification,
    ): bool {
        return $output->names === $names->value()
            && $output->relationshipTypeId === $relationshipTypeId->value()
            && $output->mobilePhone === $information->mobilePhone()
            && $output->phone === $information->phone()
            && $output->observations === $information->observations()
            && $output->documentTypeId === $identification?->documentTypeId()->value()
            && $output->documentNumber === $identification?->documentNumber();
    }

    public static function secondPrecision(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTime((int) $value->format('H'), (int) $value->format('i'), (int) $value->format('s'));
    }

    private function __construct()
    {
    }
}
