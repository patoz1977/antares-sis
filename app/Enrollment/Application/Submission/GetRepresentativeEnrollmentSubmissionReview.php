<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission;

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentStudentOption;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentPortalAuthorization;
use App\Enrollment\Application\Submission\Dto\RepresentativeEnrollmentSubmissionReview;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionContextUnavailable;
use App\Enrollment\Application\Submission\Support\EnrollmentSubmissionEvaluationContextFactory;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;

final readonly class GetRepresentativeEnrollmentSubmissionReview
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private PersonRepository $persons,
        private FamilyRepository $families,
        private EnrollmentRepository $enrollments,
        private EnrollmentSubmissionValidator $validator,
    ) {
    }

    public function handle(int $studentId): RepresentativeEnrollmentSubmissionReview
    {
        $context = $this->authorization->resolveReadContext($studentId);
        if ($context->academicPeriod === null) {
            throw new EnrollmentSubmissionContextUnavailable(
                'Enrollment Submission context is unavailable.'
            );
        }

        $student = $this->selectedStudent($context->students, $studentId);
        $representativePersonId = new PersonId($context->representativePersonId);
        $representativePerson = $this->persons->findById($representativePersonId);
        $familyId = new FamilyId($context->familyId);
        $family = $this->families->findById($familyId);
        $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
            new StudentId($studentId),
            new AcademicPeriodId($context->academicPeriod->id),
        );
        if ($student === null
            || $representativePerson === null
            || $representativePerson->id()?->equals($representativePersonId) !== true
            || $family === null
            || $family->id()?->equals($familyId) !== true
            || $enrollment === null
            || $enrollment->id() === null
            || $enrollment->studentId()->value() !== $studentId
            || $enrollment->familyId()->value() !== $context->familyId
            || $enrollment->academicPeriodId()->value() !== $context->academicPeriod->id
        ) {
            throw new EnrollmentSubmissionContextUnavailable(
                'Enrollment Submission context is unavailable.'
            );
        }

        $representativePersonOutput = PersonOutput::fromPerson(
            $representativePerson,
            $representativePersonId,
        );
        $enrollmentOutput = EnrollmentApplicationSupport::output($enrollment);
        $resources = FamilyResourcesOutput::fromFamily($family, $familyId);
        $validation = $this->validator->evaluate(
            EnrollmentSubmissionEvaluationContextFactory::fromCurrentState(
                $representativePersonOutput,
                $enrollmentOutput,
                $resources,
                $studentId,
                $context->acknowledgementsSatisfied,
            ),
        );

        return new RepresentativeEnrollmentSubmissionReview(
            $context->familyId,
            $context->familyDisplayName,
            $student,
            $context->academicPeriod,
            $enrollmentOutput,
            $representativePersonOutput,
            $resources,
            $context->acknowledgementsSatisfied,
            $validation,
        );
    }

    /** @param list<RepresentativeEnrollmentStudentOption> $students */
    private function selectedStudent(
        array $students,
        int $studentId,
    ): ?RepresentativeEnrollmentStudentOption {
        foreach ($students as $student) {
            if ($student->student->id === $studentId) {
                return $student;
            }
        }

        return null;
    }
}
