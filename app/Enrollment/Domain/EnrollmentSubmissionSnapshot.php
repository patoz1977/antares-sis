<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\ValueObject\EnrollmentSubmissionSnapshotId;
use App\Enrollment\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;

final readonly class EnrollmentSubmissionSnapshot
{
    /** @var list<SubmittedEmergencyContactSnapshot> */
    private array $emergencyContacts;

    /** @var list<SubmittedAuthorizedPickupSnapshot> */
    private array $authorizedPickups;

    /**
     * @param array<array-key, mixed> $emergencyContacts
     * @param array<array-key, mixed> $authorizedPickups
     */
    private function __construct(
        private ?EnrollmentSubmissionSnapshotId $id,
        private RepresentativeId $createdByRepresentativeId,
        private DateTimeImmutable $createdAt,
        private SubmittedAddressSnapshot $address,
        array $emergencyContacts,
        array $authorizedPickups,
        bool $persisted,
    ) {
        if ($emergencyContacts === []) {
            throw new InvalidEnrollmentState('A submission snapshot requires at least one emergency contact.');
        }

        $validatedEmergencyContacts = [];
        $sortOrders = [];
        $emergencyIds = [];
        foreach ($emergencyContacts as $contact) {
            if (!$contact instanceof SubmittedEmergencyContactSnapshot) {
                throw new InvalidEnrollmentState(
                    'Every emergency contact must be a SubmittedEmergencyContactSnapshot.',
                );
            }

            self::validateChildIdentity($contact->id()?->value(), $persisted, 'emergency contact');
            if (isset($sortOrders[$contact->sortOrder()])) {
                throw new InvalidEnrollmentState('Emergency contact sort order must be unique within a snapshot.');
            }
            $sortOrders[$contact->sortOrder()] = true;

            $contactId = $contact->id()?->value();
            if ($contactId !== null && isset($emergencyIds[$contactId])) {
                throw new InvalidEnrollmentState('Emergency contact snapshot identities must be unique.');
            }
            if ($contactId !== null) {
                $emergencyIds[$contactId] = true;
            }
            $validatedEmergencyContacts[] = $contact;
        }
        usort(
            $validatedEmergencyContacts,
            static fn (SubmittedEmergencyContactSnapshot $left, SubmittedEmergencyContactSnapshot $right): int =>
                $left->sortOrder() <=> $right->sortOrder(),
        );

        $validatedPickups = [];
        $pickupIds = [];
        foreach ($authorizedPickups as $pickup) {
            if (!$pickup instanceof SubmittedAuthorizedPickupSnapshot) {
                throw new InvalidEnrollmentState(
                    'Every authorized pickup must be a SubmittedAuthorizedPickupSnapshot.',
                );
            }

            self::validateChildIdentity($pickup->id()?->value(), $persisted, 'authorized pickup');
            $pickupId = $pickup->id()?->value();
            if ($pickupId !== null && isset($pickupIds[$pickupId])) {
                throw new InvalidEnrollmentState('Authorized pickup snapshot identities must be unique.');
            }
            if ($pickupId !== null) {
                $pickupIds[$pickupId] = true;
            }
            $validatedPickups[] = $pickup;
        }

        self::validateChildIdentity($address->id()?->value(), $persisted, 'address');
        $this->emergencyContacts = $validatedEmergencyContacts;
        $this->authorizedPickups = $validatedPickups;
    }

    /**
     * @param array<array-key, mixed> $emergencyContacts
     * @param array<array-key, mixed> $authorizedPickups
     */
    public static function create(
        RepresentativeId $createdByRepresentativeId,
        DateTimeImmutable $createdAt,
        SubmittedAddressSnapshot $address,
        array $emergencyContacts,
        array $authorizedPickups,
    ): self {
        return new self(
            null,
            $createdByRepresentativeId,
            $createdAt,
            $address,
            $emergencyContacts,
            $authorizedPickups,
            false,
        );
    }

    /**
     * @param array<array-key, mixed> $emergencyContacts
     * @param array<array-key, mixed> $authorizedPickups
     */
    public static function reconstitute(
        EnrollmentSubmissionSnapshotId $id,
        RepresentativeId $createdByRepresentativeId,
        DateTimeImmutable $createdAt,
        SubmittedAddressSnapshot $address,
        array $emergencyContacts,
        array $authorizedPickups,
    ): self {
        return new self(
            $id,
            $createdByRepresentativeId,
            $createdAt,
            $address,
            $emergencyContacts,
            $authorizedPickups,
            true,
        );
    }

    public function id(): ?EnrollmentSubmissionSnapshotId
    {
        return $this->id;
    }

    public function createdByRepresentativeId(): RepresentativeId
    {
        return $this->createdByRepresentativeId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function address(): SubmittedAddressSnapshot
    {
        return $this->address;
    }

    /** @return list<SubmittedEmergencyContactSnapshot> */
    public function emergencyContacts(): array
    {
        return $this->emergencyContacts;
    }

    /** @return list<SubmittedAuthorizedPickupSnapshot> */
    public function authorizedPickups(): array
    {
        return $this->authorizedPickups;
    }

    private static function validateChildIdentity(?int $id, bool $persisted, string $label): void
    {
        if ($persisted && $id === null) {
            throw new InvalidEnrollmentState(sprintf('A reconstituted %s snapshot requires an identity.', $label));
        }
        if (!$persisted && $id !== null) {
            throw new InvalidEnrollmentState(sprintf('A new %s snapshot cannot have a persisted identity.', $label));
        }
    }
}
