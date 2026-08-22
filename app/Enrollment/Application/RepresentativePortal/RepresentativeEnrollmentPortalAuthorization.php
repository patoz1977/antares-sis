<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentMutationContext;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentReadContext;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentStudentOption;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentAcademicPeriodUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextChanged;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentFamilySelectionRequired;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\RequireRepresentativeAcknowledgementSatisfaction;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Student\Application\Dto\StudentOutput;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\StudentId;

final readonly class RepresentativeEnrollmentPortalAuthorization
{
    public function __construct(
        private ResolveFamilyContext $resolveFamilyContext,
        private GetActiveAcademicPeriod $getActiveAcademicPeriod,
        private AcademicPeriodRepository $academicPeriods,
        private FamilyRepository $families,
        private StudentRepository $students,
        private PersonRepository $persons,
        private InstitutionalAcknowledgementSatisfaction $acknowledgements,
        private RequireRepresentativeAcknowledgementSatisfaction $requireAcknowledgements,
    ) {
    }

    public function resolveReadContext(?int $selectedStudentId = null): RepresentativeEnrollmentReadContext
    {
        $access = $this->requiredFamilyAccess();
        $context = $access->context;
        if ($context === null) {
            throw new RepresentativeEnrollmentFamilySelectionRequired(
                'Representative Enrollment Family selection is required.'
            );
        }

        $family = $this->families->findById(new FamilyId($context->familyId));
        if ($family === null || !$this->containsActiveRepresentative($family, $context->representativeId)) {
            throw new RepresentativeEnrollmentContextUnavailable(
                'Representative Enrollment context is unavailable.'
            );
        }
        $students = $this->studentOptions($family);
        if ($selectedStudentId !== null && !$this->containsStudent($students, $selectedStudentId)) {
            throw new RepresentativeEnrollmentStudentUnavailable('Selected Student is unavailable.');
        }

        $period = $this->getActiveAcademicPeriod->handle();
        $satisfied = $period !== null && $this->acknowledgements->isSatisfied(
            $context->representativeId,
            $period->id,
        );

        return new RepresentativeEnrollmentReadContext(
            $context->userId,
            $context->personId,
            $context->representativeId,
            $context->familyId,
            $context->familyDisplayName,
            count($access->authorizedFamilies) > 1,
            $students,
            $selectedStudentId,
            $period,
            $satisfied,
        );
    }

    public function resolveMutationContext(
        int $expectedFamilyId,
        int $expectedAcademicPeriodId,
        ?int $studentId = null,
    ): RepresentativeEnrollmentMutationContext {
        $this->academicPeriods->lockActiveContextForRead();
        $activePeriod = $this->academicPeriods->findActive();
        if ($activePeriod === null || $activePeriod->id() === null) {
            throw new RepresentativeEnrollmentAcademicPeriodUnavailable(
                'Representative Enrollment AcademicPeriod is unavailable.'
            );
        }
        if ($activePeriod->id()->value() !== $expectedAcademicPeriodId) {
            throw new RepresentativeEnrollmentContextChanged(
                'Representative Enrollment context changed.'
            );
        }

        $access = $this->requiredFamilyAccess();
        $context = $access->context;
        if ($context === null) {
            throw new RepresentativeEnrollmentFamilySelectionRequired(
                'Representative Enrollment Family selection is required.'
            );
        }
        if ($context->familyId !== $expectedFamilyId) {
            throw new RepresentativeEnrollmentContextChanged(
                'Representative Enrollment context changed.'
            );
        }

        $lockedFamily = $this->families->findActiveByRepresentativeAndFamilyForUpdate(
            new RepresentativeId($context->representativeId),
            new FamilyId($context->familyId),
        );
        if ($lockedFamily === null) {
            throw new RepresentativeEnrollmentContextUnavailable(
                'Representative Enrollment context is unavailable.'
            );
        }
        $this->requireAcknowledgements->handle($context->representativeId);

        if ($studentId === null) {
            return new RepresentativeEnrollmentMutationContext(
                $context->personId,
                $context->representativeId,
                $context->familyId,
                $expectedAcademicPeriodId,
            );
        }

        $lockedStudentFamily = $this->families->findActiveByStudentIdForUpdate(
            new FamilyStudentId($studentId),
        );
        if ($lockedStudentFamily?->id()?->value() !== $context->familyId) {
            throw new RepresentativeEnrollmentStudentUnavailable('Selected Student is unavailable.');
        }
        $student = $this->students->findById(new StudentId($studentId));
        $studentPersonId = $student?->personId()->value();
        if ($student === null || $student->id()?->value() !== $studentId || $studentPersonId === null) {
            throw new RepresentativeEnrollmentStudentUnavailable('Selected Student is unavailable.');
        }

        return new RepresentativeEnrollmentMutationContext(
            $context->personId,
            $context->representativeId,
            $context->familyId,
            $expectedAcademicPeriodId,
            $studentId,
            $studentPersonId,
            $lockedStudentFamily,
        );
    }

    private function requiredFamilyAccess(): \App\IdentityAccess\Application\RepresentativeFamilyAccess
    {
        $access = $this->resolveFamilyContext->handle();
        if ($access === null || $access->authorizedFamilies === []) {
            throw new RepresentativeEnrollmentContextUnavailable(
                'Representative Enrollment context is unavailable.'
            );
        }

        return $access;
    }

    /** @return list<RepresentativeEnrollmentStudentOption> */
    private function studentOptions(Family $family): array
    {
        $options = [];
        foreach ($family->activeStudents() as $membership) {
            $studentId = new StudentId($membership->studentId()->value());
            $student = $this->students->findById($studentId);
            if ($student === null || $student->id()?->equals($studentId) !== true) {
                throw new RepresentativeEnrollmentStudentUnavailable('Authorized Student is unavailable.');
            }
            $personId = new PersonId($student->personId()->value());
            $person = $this->persons->findById($personId);
            if ($person === null || $person->id()?->equals($personId) !== true) {
                throw new RepresentativeEnrollmentStudentUnavailable('Authorized Student is unavailable.');
            }
            $personOutput = PersonOutput::fromPerson($person, $personId);
            $options[] = new RepresentativeEnrollmentStudentOption(
                StudentOutput::fromStudent($student, $studentId),
                $personOutput,
                trim(implode(' ', array_filter([
                    $personOutput->firstName,
                    $personOutput->middleName,
                    $personOutput->firstSurname,
                    $personOutput->secondSurname,
                ], static fn (?string $part): bool => $part !== null && $part !== ''))),
            );
        }

        return $options;
    }

    private function containsActiveRepresentative(Family $family, int $representativeId): bool
    {
        foreach ($family->activeRepresentatives() as $membership) {
            if ($membership->representativeId()->value() === $representativeId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RepresentativeEnrollmentStudentOption> $students */
    private function containsStudent(array $students, int $studentId): bool
    {
        foreach ($students as $student) {
            if ($student->student->id === $studentId) {
                return true;
            }
        }

        return false;
    }
}
