<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Application\Administrative\Dto\AdministrativeEnrollmentReviewContext;
use App\Enrollment\Application\Administrative\Dto\AdministrativeFamilyResourcesContext;
use App\Enrollment\Application\Administrative\Dto\AdministrativeRepresentativeContext;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentContextUnavailable;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentNotSubmitted;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentUnavailable;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Application\GetFamily;
use App\Family\Application\GetFamilyResources;
use App\Person\Application\GetPerson;
use App\Representative\Application\GetRepresentative;
use App\Student\Application\GetStudent;
use Throwable;

final readonly class GetAdministrativeEnrollmentReviewContext
{
    public function __construct(
        private GetAdministrativeEnrollmentReview $getEnrollment,
        private GetStudent $getStudent,
        private GetPerson $getPerson,
        private GetFamily $getFamily,
        private GetFamilyResources $getFamilyResources,
        private GetRepresentative $getRepresentative,
        private AcademicPeriodRepository $academicPeriods,
        private AcademicPlacementReferenceProvider $academicReferences,
    ) {
    }

    public function handle(int $enrollmentId): AdministrativeEnrollmentReviewContext
    {
        try {
            $enrollment = $this->getEnrollment->handle($enrollmentId);
            if ($enrollment->status !== 'SUBMITTED') {
                throw new AdministrativeEnrollmentNotSubmitted(
                    'Administrative Enrollment is not in the Submitted queue.'
                );
            }

            $student = $this->getStudent->handle($enrollment->studentId);
            $studentPerson = $this->getPerson->handle($student->personId);
            $family = $this->getFamily->handle($enrollment->familyId);
            $resources = $this->getFamilyResources->handle($enrollment->familyId);

            $period = $this->academicPeriods->findById(
                new AcademicPeriodId($enrollment->academicPeriodId),
            );
            if ($period === null || $period->id()?->value() !== $enrollment->academicPeriodId) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Administrative Enrollment AcademicPeriod context is unavailable.'
                );
            }

            $placement = $enrollment->academicPlacement;
            $grade = $placement === null
                ? null
                : $this->academicReferences->findGradeById($placement->gradeId);
            $section = $placement?->sectionId === null
                ? null
                : $this->academicReferences->findSectionById($placement->sectionId);
            if (($placement !== null && $grade === null)
                || ($placement?->sectionId !== null && $section === null)
            ) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Administrative Enrollment AcademicPlacement context is unavailable.'
                );
            }

            $representatives = [];
            foreach ($family->representatives as $membership) {
                if (!$membership->isActive) {
                    continue;
                }
                $representative = $this->getRepresentative->handle($membership->representativeId);
                $representatives[] = new AdministrativeRepresentativeContext(
                    $representative,
                    $this->getPerson->handle($representative->personId),
                    $membership->isPrimary,
                );
            }
            usort(
                $representatives,
                static fn (
                    AdministrativeRepresentativeContext $left,
                    AdministrativeRepresentativeContext $right,
                ): int => ($right->isPrimary <=> $left->isPrimary)
                    ?: ($left->representative->id <=> $right->representative->id),
            );

            return new AdministrativeEnrollmentReviewContext(
                $enrollment,
                $student,
                $studentPerson,
                $family->id,
                $family->displayName,
                $family->status->value,
                $representatives,
                $this->studentResources($resources, $student->id),
                AcademicPeriodOutput::fromAcademicPeriod($period),
                $grade,
                $section,
            );
        } catch (AdministrativeEnrollmentUnavailable
            | AdministrativeEnrollmentNotSubmitted
            | AdministrativeEnrollmentContextUnavailable $exception
        ) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AdministrativeEnrollmentContextUnavailable(
                'Administrative Enrollment current SIS context is unavailable.',
                previous: $exception,
            );
        }
    }

    private function studentResources(
        FamilyResourcesOutput $resources,
        int $studentId,
    ): AdministrativeFamilyResourcesContext {
        $addresses = $this->indexById($resources->addresses);
        $contacts = $this->indexById($resources->emergencyContacts);
        $pickups = $this->indexById($resources->authorizedPickups);

        $addressAssignments = array_values(array_filter(
            $resources->studentAddressAssignments,
            static fn (object $assignment): bool => $assignment->isActive
                && $assignment->studentId === $studentId,
        ));
        if (count($addressAssignments) > 1) {
            throw new AdministrativeEnrollmentContextUnavailable(
                'Student has more than one current Address assignment.'
            );
        }

        $address = null;
        if ($addressAssignments !== []) {
            $address = $addresses[$addressAssignments[0]->familyAddressId] ?? null;
            if (!$address instanceof FamilyAddressOutput) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Current Student Address assignment cannot be resolved.'
                );
            }
        }

        $contactAssignments = array_values(array_filter(
            $resources->emergencyContactAssignments,
            static fn (object $assignment): bool => $assignment->isActive
                && $assignment->studentId === $studentId,
        ));
        usort(
            $contactAssignments,
            static fn (object $left, object $right): int => (($left->priority ?? PHP_INT_MAX)
                <=> ($right->priority ?? PHP_INT_MAX)) ?: ($left->id <=> $right->id),
        );
        $studentContacts = [];
        foreach ($contactAssignments as $assignment) {
            $contact = $contacts[$assignment->familyEmergencyContactId] ?? null;
            if (!$contact instanceof FamilyEmergencyContactOutput) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Current Emergency Contact assignment cannot be resolved.'
                );
            }
            $studentContacts[] = $contact;
        }

        $pickupAssignments = array_values(array_filter(
            $resources->authorizedPickupAssignments,
            static fn (object $assignment): bool => $assignment->isActive
                && $assignment->studentId === $studentId,
        ));
        usort($pickupAssignments, static fn (object $left, object $right): int => $left->id <=> $right->id);
        $studentPickups = [];
        foreach ($pickupAssignments as $assignment) {
            $pickup = $pickups[$assignment->familyAuthorizedPickupId] ?? null;
            if (!$pickup instanceof FamilyAuthorizedPickupOutput) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Current Authorized Pickup assignment cannot be resolved.'
                );
            }
            $studentPickups[] = $pickup;
        }

        return new AdministrativeFamilyResourcesContext($address, $studentContacts, $studentPickups);
    }

    /** @param list<object> $items @return array<int, object> */
    private function indexById(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (!isset($item->id) || !is_int($item->id) || $item->id <= 0 || isset($indexed[$item->id])) {
                throw new AdministrativeEnrollmentContextUnavailable(
                    'Administrative Enrollment current SIS resources are incoherent.'
                );
            }
            $indexed[$item->id] = $item;
        }

        return $indexed;
    }
}
