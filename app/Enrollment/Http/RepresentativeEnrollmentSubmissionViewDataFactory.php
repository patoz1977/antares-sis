<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\Enrollment\Application\Submission\Dto\RepresentativeEnrollmentSubmissionReview;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;

final readonly class RepresentativeEnrollmentSubmissionViewDataFactory
{
    public function __construct(private AcademicPlacementReferenceProvider $academicReferences)
    {
    }

    /** @return array<string, mixed> */
    public function make(RepresentativeEnrollmentSubmissionReview $review): array
    {
        $placement = $review->enrollment->academicPlacement;
        $grade = $placement === null
            ? null
            : $this->academicReferences->findGradeById($placement->gradeId);
        $section = $placement?->sectionId === null
            ? null
            : $this->academicReferences->findSectionById($placement->sectionId);

        return [
            'gradeName' => $grade?->name,
            'sectionName' => $section?->name,
            'studentAddresses' => $this->studentAddresses($review),
            'emergencyContacts' => $this->emergencyContacts($review),
            'authorizedPickups' => $this->authorizedPickups($review),
        ];
    }

    /** @return list<FamilyAddressOutput> */
    private function studentAddresses(RepresentativeEnrollmentSubmissionReview $review): array
    {
        $addresses = [];
        foreach ($review->familyResources->studentAddressAssignments as $assignment) {
            if (!$assignment->isActive || $assignment->studentId !== $review->student->student->id) {
                continue;
            }
            $address = $this->address($review, $assignment->familyAddressId);
            if ($address !== null && $address->status === 'ACTIVE') {
                $addresses[] = $address;
            }
        }

        usort($addresses, static fn (FamilyAddressOutput $left, FamilyAddressOutput $right): int =>
            $left->id <=> $right->id);

        return $addresses;
    }

    /** @return list<array{contact: FamilyEmergencyContactOutput, priority: ?int}> */
    private function emergencyContacts(RepresentativeEnrollmentSubmissionReview $review): array
    {
        $contacts = [];
        foreach ($review->familyResources->emergencyContactAssignments as $assignment) {
            if (!$assignment->isActive || $assignment->studentId !== $review->student->student->id) {
                continue;
            }
            $contact = $this->emergencyContact($review, $assignment->familyEmergencyContactId);
            if ($contact !== null && $contact->status === 'ACTIVE') {
                $contacts[] = ['contact' => $contact, 'priority' => $assignment->priority];
            }
        }

        usort($contacts, static fn (array $left, array $right): int =>
            [$left['priority'] ?? PHP_INT_MAX, $left['contact']->id]
            <=> [$right['priority'] ?? PHP_INT_MAX, $right['contact']->id]);

        return $contacts;
    }

    /** @return list<FamilyAuthorizedPickupOutput> */
    private function authorizedPickups(RepresentativeEnrollmentSubmissionReview $review): array
    {
        $pickups = [];
        foreach ($review->familyResources->authorizedPickupAssignments as $assignment) {
            if (!$assignment->isActive || $assignment->studentId !== $review->student->student->id) {
                continue;
            }
            $pickup = $this->authorizedPickup($review, $assignment->familyAuthorizedPickupId);
            if ($pickup !== null && $pickup->status === 'ACTIVE') {
                $pickups[] = $pickup;
            }
        }

        usort($pickups, static fn (FamilyAuthorizedPickupOutput $left, FamilyAuthorizedPickupOutput $right): int =>
            $left->id <=> $right->id);

        return $pickups;
    }

    private function address(
        RepresentativeEnrollmentSubmissionReview $review,
        int $addressId,
    ): ?FamilyAddressOutput {
        foreach ($review->familyResources->addresses as $address) {
            if ($address->id === $addressId) {
                return $address;
            }
        }

        return null;
    }

    private function emergencyContact(
        RepresentativeEnrollmentSubmissionReview $review,
        int $contactId,
    ): ?FamilyEmergencyContactOutput {
        foreach ($review->familyResources->emergencyContacts as $contact) {
            if ($contact->id === $contactId) {
                return $contact;
            }
        }

        return null;
    }

    private function authorizedPickup(
        RepresentativeEnrollmentSubmissionReview $review,
        int $pickupId,
    ): ?FamilyAuthorizedPickupOutput {
        foreach ($review->familyResources->authorizedPickups as $pickup) {
            if ($pickup->id === $pickupId) {
                return $pickup;
            }
        }

        return null;
    }
}
