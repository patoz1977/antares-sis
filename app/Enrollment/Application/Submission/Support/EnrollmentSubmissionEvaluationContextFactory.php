<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Support;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionEvaluationContext;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Domain\FamilyResourceStatus;
use App\Person\Application\Dto\PersonOutput;

final class EnrollmentSubmissionEvaluationContextFactory
{
    public static function fromCurrentState(
        PersonOutput $representativePerson,
        EnrollmentOutput $enrollment,
        FamilyResourcesOutput $resources,
        int $studentId,
        bool $acknowledgementsSatisfied,
    ): EnrollmentSubmissionEvaluationContext {
        $activeAddressIds = self::activeIds($resources->addresses);
        $activeEmergencyIds = self::activeIds($resources->emergencyContacts);
        $activePickupIds = self::activeIds($resources->authorizedPickups);

        return new EnrollmentSubmissionEvaluationContext(
            $enrollment->status === EnrollmentStatus::Draft->value,
            $representativePerson->email !== null && $representativePerson->email !== '',
            $representativePerson->mobilePhone !== null && $representativePerson->mobilePhone !== '',
            self::activeAssignmentCount(
                $resources->studentAddressAssignments,
                $studentId,
                $activeAddressIds,
                'familyAddressId',
            ),
            $enrollment->billingInformation !== null,
            $enrollment->medicalInformation !== null,
            $enrollment->transportInformation !== null,
            self::activeAssignmentCount(
                $resources->emergencyContactAssignments,
                $studentId,
                $activeEmergencyIds,
                'familyEmergencyContactId',
            ),
            self::activeAssignmentCount(
                $resources->authorizedPickupAssignments,
                $studentId,
                $activePickupIds,
                'familyAuthorizedPickupId',
            ),
            $enrollment->isAuthorizedToLeaveAlone,
            $acknowledgementsSatisfied,
            $enrollment->academicPlacement !== null,
        );
    }

    /** @param list<object> $resources @return array<int, true> */
    private static function activeIds(array $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            if ($resource->status === FamilyResourceStatus::Active->value) {
                $ids[$resource->id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param list<object> $assignments
     * @param array<int, true> $activeResourceIds
     */
    private static function activeAssignmentCount(
        array $assignments,
        int $studentId,
        array $activeResourceIds,
        string $resourceIdProperty,
    ): int {
        $count = 0;
        foreach ($assignments as $assignment) {
            if ($assignment->studentId === $studentId
                && $assignment->isActive
                && isset($activeResourceIds[$assignment->{$resourceIdProperty}])
            ) {
                ++$count;
            }
        }

        return $count;
    }

    private function __construct()
    {
    }
}
