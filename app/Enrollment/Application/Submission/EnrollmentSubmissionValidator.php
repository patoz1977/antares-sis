<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission;

use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionEvaluationContext;
use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionRequirement;
use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionValidationResult;

final readonly class EnrollmentSubmissionValidator
{
    public function evaluate(
        EnrollmentSubmissionEvaluationContext $context,
    ): EnrollmentSubmissionValidationResult {
        return new EnrollmentSubmissionValidationResult([
            new EnrollmentSubmissionRequirement(
                'ENROLLMENT_DRAFT',
                'The enrollment must be open for submission.',
                $context->enrollmentIsDraft,
            ),
            new EnrollmentSubmissionRequirement(
                'REPRESENTATIVE_EMAIL',
                'Add a personal email address for the representative.',
                $context->representativeHasPersonalEmail,
            ),
            new EnrollmentSubmissionRequirement(
                'REPRESENTATIVE_MOBILE',
                'Add a mobile phone for the representative.',
                $context->representativeHasMobilePhone,
            ),
            new EnrollmentSubmissionRequirement(
                'STUDENT_ADDRESS',
                'Assign one current address to the student.',
                $context->activeStudentAddressCount === 1,
            ),
            new EnrollmentSubmissionRequirement(
                'BILLING',
                'Complete the enrollment billing information.',
                $context->hasBillingInformation,
            ),
            new EnrollmentSubmissionRequirement(
                'MEDICAL',
                'Complete the enrollment medical information.',
                $context->hasMedicalInformation,
            ),
            new EnrollmentSubmissionRequirement(
                'TRANSPORT',
                'Complete the enrollment transport information.',
                $context->hasTransportInformation,
            ),
            new EnrollmentSubmissionRequirement(
                'EMERGENCY_CONTACT',
                'Assign at least one current emergency contact to the student.',
                $context->activeEmergencyContactCount >= 1,
            ),
            new EnrollmentSubmissionRequirement(
                'PICKUP_OR_LEAVE_ALONE',
                'Assign an authorized pickup or authorize the student to leave alone.',
                $context->activeAuthorizedPickupCount >= 1 || $context->isAuthorizedToLeaveAlone,
            ),
            new EnrollmentSubmissionRequirement(
                'ACKNOWLEDGEMENTS',
                'Complete the current institutional acknowledgements.',
                $context->acknowledgementsSatisfied,
            ),
            new EnrollmentSubmissionRequirement(
                'ACADEMIC_PLACEMENT',
                'Academic placement must be assigned before submission.',
                $context->hasAcademicPlacement,
            ),
        ]);
    }
}
