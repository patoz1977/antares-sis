<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentProgress;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentStudentOption;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Family\Application\GetFamilyResources;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class GetRepresentativeEnrollmentPortalState
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
        private EnrollmentRepository $enrollments,
        private GetFamilyResources $familyResources,
    ) {
    }

    public function handle(?int $selectedStudentId = null): RepresentativeEnrollmentPortalState
    {
        $context = $this->authorization->resolveReadContext($selectedStudentId);
        $personId = new PersonId($context->representativePersonId);
        $representativePerson = $this->persons->findById($personId);
        $representativeId = new RepresentativeId($context->representativeId);
        $representative = $this->representatives->findById($representativeId);
        if ($representativePerson === null
            || $representativePerson->id()?->equals($personId) !== true
            || $representative === null
            || $representative->id()?->equals($representativeId) !== true
            || $representative->personId()->value() !== $context->representativePersonId
        ) {
            throw new RepresentativeEnrollmentContextUnavailable(
                'Representative Enrollment context is unavailable.'
            );
        }

        $selectedStudent = $this->selectedStudent($context->students, $selectedStudentId);
        $enrollment = null;
        if ($selectedStudent !== null && $context->academicPeriod !== null) {
            $current = $this->enrollments->findByStudentAndAcademicPeriod(
                new StudentId($selectedStudent->student->id),
                new AcademicPeriodId($context->academicPeriod->id),
            );
            if ($current !== null && $current->familyId()->value() !== $context->familyId) {
                throw new RepresentativeEnrollmentContextUnavailable(
                    'Representative Enrollment context is unavailable.'
                );
            }
            $enrollment = $current === null ? null : EnrollmentApplicationSupport::output($current);
        }

        $resources = $selectedStudent === null
            ? null
            : $this->familyResources->handle($context->familyId);
        $progress = $this->progress(
            $context->acknowledgementsSatisfied,
            PersonOutput::fromPerson($representativePerson, $personId),
            $selectedStudent,
            $enrollment,
            $resources,
        );
        $maintenanceEnabled = $context->academicPeriod !== null
            && $context->acknowledgementsSatisfied;

        return new RepresentativeEnrollmentPortalState(
            $context,
            PersonOutput::fromPerson($representativePerson, $personId),
            RepresentativeOutput::fromRepresentative($representative, $representativeId),
            $selectedStudent,
            $enrollment,
            $context->academicPeriod !== null,
            $maintenanceEnabled,
            $enrollment !== null && $enrollment->status !== EnrollmentStatus::Draft->value,
            $progress,
        );
    }

    /** @param list<RepresentativeEnrollmentStudentOption> $students */
    private function selectedStudent(array $students, ?int $selectedStudentId): ?RepresentativeEnrollmentStudentOption
    {
        if ($selectedStudentId === null) {
            return null;
        }
        foreach ($students as $student) {
            if ($student->student->id === $selectedStudentId) {
                return $student;
            }
        }

        return null;
    }

    private function progress(
        bool $acknowledgementsSatisfied,
        PersonOutput $representative,
        ?RepresentativeEnrollmentStudentOption $student,
        ?\App\Enrollment\Application\Dto\EnrollmentOutput $enrollment,
        ?\App\Family\Application\Dto\FamilyResourcesOutput $resources,
    ): RepresentativeEnrollmentProgress {
        $complete = RepresentativeEnrollmentSectionStatus::Complete;
        $pending = RepresentativeEnrollmentSectionStatus::Pending;
        $studentId = $student?->student->id;
        $hasStudentAddress = false;
        $hasEmergency = false;
        $hasPickup = false;
        if ($studentId !== null && $resources !== null) {
            foreach ($resources->studentAddressAssignments as $assignment) {
                $hasStudentAddress = $hasStudentAddress
                    || ($assignment->studentId === $studentId && $assignment->isActive);
            }
            foreach ($resources->emergencyContactAssignments as $assignment) {
                $hasEmergency = $hasEmergency
                    || ($assignment->studentId === $studentId && $assignment->isActive);
            }
            foreach ($resources->authorizedPickupAssignments as $assignment) {
                $hasPickup = $hasPickup
                    || ($assignment->studentId === $studentId && $assignment->isActive);
            }
        }

        return new RepresentativeEnrollmentProgress(
            $acknowledgementsSatisfied ? $complete : $pending,
            $representative->firstName !== '' && $representative->firstSurname !== '' ? $complete : $pending,
            $representative->email !== null && $representative->email !== '' ? $complete : $pending,
            $complete,
            $student !== null && $student->person->firstName !== '' && $student->person->firstSurname !== ''
                ? $complete : $pending,
            $hasStudentAddress ? $complete : $pending,
            $enrollment?->academicPlacement !== null ? $complete : $pending,
            $enrollment?->billingInformation !== null ? $complete : $pending,
            $enrollment?->medicalInformation !== null ? $complete : $pending,
            $enrollment?->transportInformation !== null ? $complete : $pending,
            $hasEmergency ? $complete : $pending,
            ($hasPickup || $enrollment?->isAuthorizedToLeaveAlone === true) ? $complete : $pending,
        );
    }
}
