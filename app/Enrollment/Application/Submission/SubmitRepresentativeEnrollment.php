<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission;

use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Submission\Dto\SubmitRepresentativeEnrollmentInput;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionContextUnavailable;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionNotReady;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionPersistedStateMismatch;
use App\Enrollment\Application\Submission\Support\EnrollmentSubmissionEvaluationContextFactory;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId as EnrollmentAcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSubmissionSatisfaction;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SubmitRepresentativeEnrollment
{
    public function __construct(
        private ResolveFamilyContext $resolveFamilyContext,
        private AcademicPeriodRepository $academicPeriods,
        private InstitutionalAcknowledgementSubmissionSatisfaction $acknowledgements,
        private FamilyRepository $families,
        private StudentRepository $students,
        private PersonRepository $persons,
        private EnrollmentRepository $enrollments,
        private EnrollmentSubmissionValidator $validator,
        private TransactionRunner $transactions,
        private Clock $clock,
    ) {
    }

    public function handle(SubmitRepresentativeEnrollmentInput $input): EnrollmentOutput
    {
        return $this->transactions->run(function () use ($input): EnrollmentOutput {
            $this->assertPositiveInput($input);
            $access = $this->resolveFamilyContext->handle();
            $context = $access?->context;
            if ($context === null
                || $context->familyId !== $input->expectedFamilyId
                || $context->representativeId !== $access->representative->representativeId
                || $context->personId !== $access->representative->personId
                || $context->userId !== $access->representative->userId
            ) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission context is unavailable.'
                );
            }

            $this->academicPeriods->lockActiveContextForRead();
            $period = $this->academicPeriods->findActive();
            if ($period === null
                || $period->id()?->value() !== $input->expectedAcademicPeriodId
            ) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission AcademicPeriod context changed.'
                );
            }

            $acknowledgementsSatisfied = $this->acknowledgements->isSatisfiedInStableContext(
                $context->representativeId,
                $input->expectedAcademicPeriodId,
            );

            $familyId = new FamilyId($input->expectedFamilyId);
            $rootFamily = $this->families->findByIdForUpdate($familyId);
            if ($rootFamily === null || $rootFamily->id()?->equals($familyId) !== true) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Family context is unavailable.'
                );
            }

            $representativeFamily = $this->families->findActiveByRepresentativeAndFamilyForUpdate(
                new FamilyRepresentativeId($context->representativeId),
                $familyId,
            );
            if ($representativeFamily?->id()?->equals($familyId) !== true) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Family context is unavailable.'
                );
            }

            $studentFamily = $this->families->findActiveByStudentIdForUpdate(
                new FamilyStudentId($input->studentId),
            );
            if ($studentFamily?->id()?->equals($familyId) !== true) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Student context is unavailable.'
                );
            }

            $studentId = new StudentId($input->studentId);
            $student = $this->students->findById($studentId);
            if ($student === null || $student->id()?->equals($studentId) !== true) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Student context is unavailable.'
                );
            }

            $lockedPeople = $this->lockPeople([
                $context->personId,
                $student->personId()->value(),
            ]);
            $representativePerson = $lockedPeople[$context->personId] ?? null;
            $studentPersonId = $student->personId()->value();
            $studentPerson = $lockedPeople[$studentPersonId] ?? null;
            if ($representativePerson === null || $studentPerson === null) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Person context is unavailable.'
                );
            }

            $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
                new EnrollmentStudentId($input->studentId),
                new EnrollmentAcademicPeriodId($input->expectedAcademicPeriodId),
            );
            $enrollmentId = $enrollment?->id();
            if ($enrollmentId === null) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Enrollment context is unavailable.'
                );
            }
            $enrollment = $this->enrollments->findByIdForUpdate($enrollmentId);
            if ($enrollment === null
                || $enrollment->id()?->equals($enrollmentId) !== true
                || $enrollment->studentId()->value() !== $input->studentId
                || $enrollment->familyId()->value() !== $input->expectedFamilyId
                || $enrollment->academicPeriodId()->value() !== $input->expectedAcademicPeriodId
            ) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Enrollment context is unavailable.'
                );
            }

            $representativePersonOutput = PersonOutput::fromPerson(
                $representativePerson,
                new PersonId($context->personId),
            );
            PersonOutput::fromPerson($studentPerson, new PersonId($studentPersonId));
            $before = EnrollmentApplicationSupport::output($enrollment);
            $resources = FamilyResourcesOutput::fromFamily($studentFamily, $familyId);
            $validation = $this->validator->evaluate(
                EnrollmentSubmissionEvaluationContextFactory::fromCurrentState(
                    $representativePersonOutput,
                    $before,
                    $resources,
                    $input->studentId,
                    $acknowledgementsSatisfied,
                ),
            );
            if (!$validation->isSubmittable) {
                throw new EnrollmentSubmissionNotReady($validation);
            }

            $submittedAt = $this->clock->now();
            $enrollment->submit($submittedAt);
            $saved = EnrollmentApplicationSupport::save($this->enrollments, $enrollment);
            $this->assertPersistedSubmission($saved, $before, $submittedAt);

            $reloaded = $this->enrollments->findById(new EnrollmentId($before->id));
            if ($reloaded === null) {
                throw new EnrollmentSubmissionPersistedStateMismatch(
                    'Submitted Enrollment could not be reloaded.'
                );
            }
            $result = EnrollmentApplicationSupport::output($reloaded);
            $this->assertPersistedSubmission($result, $before, $submittedAt);

            return $result;
        });
    }

    private function assertPositiveInput(SubmitRepresentativeEnrollmentInput $input): void
    {
        if ($input->expectedFamilyId <= 0
            || $input->expectedAcademicPeriodId <= 0
            || $input->studentId <= 0
        ) {
            throw new EnrollmentSubmissionContextUnavailable(
                'Enrollment Submission context is unavailable.'
            );
        }
    }

    /** @param list<int> $personIds @return array<int, Person> */
    private function lockPeople(array $personIds): array
    {
        $personIds = array_values(array_unique($personIds));
        sort($personIds, SORT_NUMERIC);
        $people = [];
        foreach ($personIds as $value) {
            $id = new PersonId($value);
            $person = $this->persons->findByIdForUpdate($id);
            if ($person === null || $person->id()?->equals($id) !== true) {
                throw new EnrollmentSubmissionContextUnavailable(
                    'Enrollment Submission Person context is unavailable.'
                );
            }
            $people[$value] = $person;
        }

        return $people;
    }

    private function assertPersistedSubmission(
        EnrollmentOutput $persisted,
        EnrollmentOutput $before,
        DateTimeImmutable $submittedAt,
    ): void {
        if ($persisted->id !== $before->id
            || $persisted->studentId !== $before->studentId
            || $persisted->familyId !== $before->familyId
            || $persisted->academicPeriodId !== $before->academicPeriodId
            || $persisted->status !== EnrollmentStatus::Submitted->value
            || $persisted->submittedAt === null
            || $this->utcSecond($persisted->submittedAt) !== $this->utcSecond($submittedAt)
            || $persisted->completedAt !== null
            || $persisted->cancelledAt !== null
            || $persisted->academicPlacement != $before->academicPlacement
            || $persisted->billingInformation != $before->billingInformation
            || $persisted->medicalInformation != $before->medicalInformation
            || $persisted->transportInformation != $before->transportInformation
            || $persisted->isAuthorizedToLeaveAlone !== $before->isAuthorizedToLeaveAlone
            || $this->utcSecond($persisted->startedAt) !== $this->utcSecond($before->startedAt)
        ) {
            throw new EnrollmentSubmissionPersistedStateMismatch(
                'Submitted Enrollment persistence returned incoherent state.'
            );
        }
    }

    private function utcSecond(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
