<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionEvaluationContext;
use App\Enrollment\Application\Submission\Dto\SubmitRepresentativeEnrollmentInput;
use App\Enrollment\Application\Submission\EnrollmentSubmissionValidator;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionContextUnavailable;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionNotReady;
use App\Enrollment\Application\Submission\GetRepresentativeEnrollmentSubmissionReview;
use App\Enrollment\Application\Submission\SubmitRepresentativeEnrollment;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId as EnrollmentFamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyResourceStatus;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\StudentAddressAssignment;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupAssignmentId;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\EmergencyContactAssignmentId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId as FamilyStudentMembershipId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\Contract\Clock;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSubmissionSatisfaction;
use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSubmissionSatisfaction;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentSubmissionApplicationTests(TestRunner $runner): void
{
    $runner->add('E012 Submission validator is the single deterministic completeness source', function (): void {
        $validator = new EnrollmentSubmissionValidator();
        $complete = e012Evaluation();
        $result = $validator->evaluate($complete);

        assertSameValue(true, $result->isSubmittable);
        assertSameValue([
            'ENROLLMENT_DRAFT', 'REPRESENTATIVE_EMAIL', 'REPRESENTATIVE_MOBILE',
            'STUDENT_ADDRESS', 'BILLING', 'MEDICAL', 'TRANSPORT', 'EMERGENCY_CONTACT',
            'PICKUP_OR_LEAVE_ALONE', 'ACKNOWLEDGEMENTS', 'ACADEMIC_PLACEMENT',
        ], array_map(static fn ($requirement): string => $requirement->code, $result->requirements));

        foreach ([
            'enrollmentIsDraft' => false,
            'representativeHasPersonalEmail' => false,
            'representativeHasMobilePhone' => false,
            'activeStudentAddressCount' => 0,
            'activeStudentAddressCount:multiple' => 2,
            'hasBillingInformation' => false,
            'hasMedicalInformation' => false,
            'hasTransportInformation' => false,
            'activeEmergencyContactCount' => 0,
            'activeAuthorizedPickupCount' => 0,
            'acknowledgementsSatisfied' => false,
            'hasAcademicPlacement' => false,
        ] as $case => $value) {
            $field = explode(':', $case)[0];
            $overrides = [$field => $value];
            if ($field === 'activeAuthorizedPickupCount') {
                $overrides['isAuthorizedToLeaveAlone'] = false;
            }
            assertSameValue(false, $validator->evaluate(e012Evaluation($overrides))->isSubmittable, $case);
        }

        assertSameValue(true, $validator->evaluate(e012Evaluation([
            'activeAuthorizedPickupCount' => 0,
            'isAuthorizedToLeaveAlone' => true,
        ]))->isSubmittable);
    });

    $runner->add('E012 Review returns current live state without Clock transition or persistence', function (): void {
        $fixture = e012SubmissionFixture();
        $beforeSaves = $fixture['enrollments']->saveCalls;
        $review = $fixture['review']->handle(44);

        assertSameValue(true, $review->validation->isSubmittable);
        assertSameValue(77, $review->familyId);
        assertSameValue(44, $review->student->student->id);
        assertSameValue(5, $review->academicPeriod->id);
        assertSameValue('stored@example.test', $review->representativePerson->email);
        assertSameValue(true, $review->acknowledgementsSatisfied);
        assertSameValue($beforeSaves, $fixture['enrollments']->saveCalls);
        assertSameValue(0, $fixture['clock']->calls);
    });

    $runner->add('E012 Review exposes pending acknowledgements and non-Draft readiness safely', function (): void {
        $pending = e012SubmissionFixture(acknowledgementsSatisfied: false);
        $review = $pending['review']->handle(44);
        assertSameValue(false, $review->validation->isSubmittable);
        assertSameValue(false, e012Requirement($review->validation, 'ACKNOWLEDGEMENTS')->satisfied);

        $submitted = e012SubmissionFixture(status: EnrollmentStatus::Submitted);
        $review = $submitted['review']->handle(44);
        assertSameValue(false, $review->validation->isSubmittable);
        assertSameValue(false, e012Requirement($review->validation, 'ENROLLMENT_DRAFT')->satisfied);
    });

    $runner->add('E012 Review fails closed for missing period Enrollment or authorized Student', function (): void {
        assertThrows(
            static fn () => e012SubmissionFixture(periodActive: false)['review']->handle(44),
            EnrollmentSubmissionContextUnavailable::class,
        );

        $missing = e012SubmissionFixture(seedEnrollment: false);
        assertThrows(
            static fn () => $missing['review']->handle(44),
            EnrollmentSubmissionContextUnavailable::class,
        );
        assertThrows(
            static fn () => e012SubmissionFixture()['review']->handle(999),
            \App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable::class,
        );
    });

    $runner->add('E012 Submit performs one exact first transition and verifies persisted annual state', function (): void {
        $fixture = e012SubmissionFixture();
        $result = $fixture['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44));

        assertSameValue(900, $result->id);
        assertSameValue(EnrollmentStatus::Submitted->value, $result->status);
        assertSameValue('2026-08-21 15:16:17', $result->submittedAt?->format('Y-m-d H:i:s'));
        assertSameValue(null, $result->completedAt);
        assertSameValue(null, $result->cancelledAt);
        assertSameValue(3, $result->academicPlacement?->gradeId);
        assertSameValue('BILL-44', $result->billingInformation?->identificationNumber);
        assertSameValue(false, $result->transportInformation?->requiresInstitutionalTransport);
        assertSameValue(false, $result->isAuthorizedToLeaveAlone);
        assertSameValue(1, $fixture['clock']->calls);
        assertSameValue(1, $fixture['enrollments']->saveCalls);
        assertSameValue([
            'status-lock', 'ack-lock', 'family-root-lock', 'representative-membership-lock',
            'student-membership-lock', 'person-lock:10', 'person-lock:22', 'enrollment-lock',
        ], $fixture['trace']->events);
    });

    $runner->add('E012 Submit rejects incomplete state before Clock mutation or save', function (): void {
        $fixture = e012SubmissionFixture(stableAcknowledgementsSatisfied: false);
        $failure = null;
        try {
            $fixture['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44));
        } catch (EnrollmentSubmissionNotReady $exception) {
            $failure = $exception;
        }

        assertSameValue(true, $failure instanceof EnrollmentSubmissionNotReady);
        assertSameValue(false, e012Requirement($failure?->validation, 'ACKNOWLEDGEMENTS')->satisfied);
        assertSameValue(0, $fixture['clock']->calls);
        assertSameValue(0, $fixture['enrollments']->saveCalls);
        assertSameValue(['begin', 'rollback'], $fixture['transactions']->events);
    });

    $runner->add('E012 Submit rejects stale Family period membership Enrollment and duplicate proposals', function (): void {
        foreach ([
            new SubmitRepresentativeEnrollmentInput(78, 5, 44),
            new SubmitRepresentativeEnrollmentInput(77, 6, 44),
            new SubmitRepresentativeEnrollmentInput(77, 5, 999),
        ] as $input) {
            $fixture = e012SubmissionFixture();
            assertThrows(
                static fn () => $fixture['submit']->handle($input),
                EnrollmentSubmissionContextUnavailable::class,
            );
            assertSameValue(0, $fixture['clock']->calls);
            assertSameValue(0, $fixture['enrollments']->saveCalls);
        }

        $inactive = e012SubmissionFixture(activeStudentMembership: false);
        assertThrows(
            static fn () => $inactive['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44)),
            EnrollmentSubmissionContextUnavailable::class,
        );

        $duplicate = e012SubmissionFixture();
        $duplicate['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44));
        assertThrows(
            static fn () => $duplicate['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44)),
            EnrollmentSubmissionNotReady::class,
        );
        assertSameValue(1, $duplicate['clock']->calls);
        assertSameValue(1, $duplicate['enrollments']->saveCalls);
    });

    $runner->add('E012 Submit supports same-Enrollment Resubmission with later Clock time', function (): void {
        $fixture = e012SubmissionFixture();
        $first = $fixture['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44));
        $enrollment = $fixture['enrollments']->findById(new EnrollmentId($first->id))
            ?? throw new RuntimeException('Submitted Enrollment fixture is missing.');
        $enrollment->reopen();
        $fixture['enrollments']->save($enrollment);
        $fixture['enrollments']->resetObservations();
        $laterClock = new E010FakeClock(new DateTimeImmutable('2026-08-21 16:17:18+00:00'));
        $submit = e012SubmitFromFixture($fixture, $laterClock);

        $resubmitted = $submit->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44));
        assertSameValue($first->id, $resubmitted->id);
        assertSameValue('2026-08-21 16:17:18', $resubmitted->submittedAt?->format('Y-m-d H:i:s'));
        assertSameValue(1, $laterClock->calls);
        assertSameValue(1, $fixture['enrollments']->saveCalls);
    });

    $runner->add('E012 Submit rollback leaves the persisted Draft and releases transaction ownership', function (): void {
        $fixture = e012SubmissionFixture();
        $fixture['enrollments']->saveFailure = new RuntimeException('simulated submission save failure');
        assertThrows(
            static fn () => $fixture['submit']->handle(new SubmitRepresentativeEnrollmentInput(77, 5, 44)),
            RuntimeException::class,
        );

        $persisted = $fixture['enrollments']->findById(new EnrollmentId(900));
        assertSameValue(EnrollmentStatus::Draft, $persisted?->status());
        assertSameValue(null, $persisted?->submittedAt());
        assertSameValue(['begin', 'rollback'], $fixture['transactions']->events);
        assertSameValue(false, $fixture['transactions']->active);
    });

    $runner->add('E012 stable acknowledgement boundary locks configuration before exact satisfaction evaluation', function (): void {
        $zeroRequirements = new ApplicationRequirementRepository();
        $completions = new ApplicationCompletionRepository();
        $stable = new CheckInstitutionalAcknowledgementSubmissionSatisfaction(
            $zeroRequirements,
            $completions,
        );
        assertSameValue(true, $stable->isSatisfiedInStableContext(33, 5));
        assertSameValue(['lock:scope-read:5'], $zeroRequirements->operationLog);

        $requirement = applicationRequirement(
            51,
            5,
            'Current policy',
            '/policy',
            null,
            AcknowledgementRequirementStatus::Active,
        );
        $requirements = new ApplicationRequirementRepository([$requirement]);
        $stable = new CheckInstitutionalAcknowledgementSubmissionSatisfaction($requirements, $completions);
        assertSameValue(false, $stable->isSatisfiedInStableContext(33, 5));
        $completions->save(applicationNewCompletion(33, 5, [$requirement]));
        assertSameValue(true, $stable->isSatisfiedInStableContext(33, 5));
    });

    $runner->add('E012 Submission Application preserves bounded-context and Delivery exclusions', function (): void {
        $directory = dirname(__DIR__) . '/app/Enrollment/Application/Submission';
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Request', 'Response',
            'SessionManager', 'Controller', 'Infrastructure\\', 'SubmissionSnapshot'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        $validator = (string) file_get_contents(
            $directory . '/EnrollmentSubmissionValidator.php'
        );
        foreach (['Repository', 'TransactionRunner', 'Clock', 'Session'] as $forbidden) {
            assertSameValue(false, str_contains($validator, $forbidden), $forbidden);
        }
        assertSameValue(false, str_contains(
            (string) file_get_contents(dirname(__DIR__) . '/app/Family/Domain/Family.php'),
            'App\\Enrollment',
        ));
        assertSameValue(false, is_dir(dirname(__DIR__) . '/app/Enrollment/Delivery/Submission'));
        assertSameValue(false, file_exists(dirname(__DIR__) . '/database/migrations/011_create_submission.php'));
    });
}

/** @param array<string, bool|int> $overrides */
function e012Evaluation(array $overrides = []): EnrollmentSubmissionEvaluationContext
{
    $values = array_merge([
        'enrollmentIsDraft' => true,
        'representativeHasPersonalEmail' => true,
        'representativeHasMobilePhone' => true,
        'activeStudentAddressCount' => 1,
        'hasBillingInformation' => true,
        'hasMedicalInformation' => true,
        'hasTransportInformation' => true,
        'activeEmergencyContactCount' => 1,
        'activeAuthorizedPickupCount' => 1,
        'isAuthorizedToLeaveAlone' => false,
        'acknowledgementsSatisfied' => true,
        'hasAcademicPlacement' => true,
    ], $overrides);

    return new EnrollmentSubmissionEvaluationContext(...array_values($values));
}

function e012Requirement(
    ?\App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionValidationResult $result,
    string $code,
): \App\Enrollment\Application\Submission\Dto\EnrollmentSubmissionRequirement {
    foreach ($result?->requirements ?? [] as $requirement) {
        if ($requirement->code === $code) {
            return $requirement;
        }
    }

    throw new RuntimeException('Submission requirement fixture was not found.');
}

/** @return array<string, mixed> */
function e012SubmissionFixture(
    bool $acknowledgementsSatisfied = true,
    bool $stableAcknowledgementsSatisfied = true,
    bool $periodActive = true,
    bool $activeStudentMembership = true,
    bool $seedEnrollment = true,
    EnrollmentStatus $status = EnrollmentStatus::Draft,
): array {
    $base = e011PortalFixture(
        acknowledgementsSatisfied: $acknowledgementsSatisfied,
        periodActive: $periodActive,
    );
    $base['families']->seed(e012CompleteFamily($activeStudentMembership));
    if ($seedEnrollment) {
        $base['enrollments']->seed(e012CompleteEnrollment($status));
        $base['enrollments']->resetObservations();
    }

    $trace = new E012SubmissionTrace();
    $families = new E012TracingFamilyRepository($base['families'], $trace);
    $periods = new E012TracingAcademicPeriodRepository($base['periods'], $trace);
    $persons = new E012TracingPersonRepository($base['persons'], $trace);
    $enrollments = new E012TracingEnrollmentRepository($base['enrollments'], $trace);
    $stableAcknowledgements = new E012StableAcknowledgements(
        $stableAcknowledgementsSatisfied,
        $trace,
    );
    $clock = new E010FakeClock(new DateTimeImmutable('2026-08-21 15:16:17.987654+00:00'));
    $validator = new EnrollmentSubmissionValidator();

    $base['trace'] = $trace;
    $base['familiesForSubmit'] = $families;
    $base['periodsForSubmit'] = $periods;
    $base['personsForSubmit'] = $persons;
    $base['enrollmentsForSubmit'] = $enrollments;
    $base['stableAcknowledgements'] = $stableAcknowledgements;
    $base['clock'] = $clock;
    $base['validator'] = $validator;
    $base['review'] = new GetRepresentativeEnrollmentSubmissionReview(
        $base['authorization'],
        $base['persons'],
        $base['families'],
        $base['enrollments'],
        $validator,
    );
    $base['submit'] = e012SubmitFromFixture($base, $clock);

    return $base;
}

/** @param array<string, mixed> $fixture */
function e012SubmitFromFixture(array $fixture, Clock $clock): SubmitRepresentativeEnrollment
{
    return new SubmitRepresentativeEnrollment(
        $fixture['resolveFamilyContext'],
        $fixture['periodsForSubmit'],
        $fixture['stableAcknowledgements'],
        $fixture['familiesForSubmit'],
        $fixture['students'],
        $fixture['personsForSubmit'],
        $fixture['enrollmentsForSubmit'],
        $fixture['validator'],
        $fixture['transactions'],
        $clock,
    );
}

function e012CompleteEnrollment(EnrollmentStatus $status): Enrollment
{
    $submittedAt = $status === EnrollmentStatus::Draft
        ? null
        : new DateTimeImmutable('2026-08-20 12:00:00+00:00');

    return Enrollment::reconstitute(
        new EnrollmentId(900),
        new EnrollmentStudentId(44),
        new EnrollmentFamilyId(77),
        new AcademicPeriodId(5),
        $status,
        new AcademicPlacement(new GradeId(3), new SectionId(9)),
        new BillingInformation(
            new IdentificationTypeId(1),
            'BILL-44',
            'Representative Legal Name',
            'Current billing address',
            'billing@example.test',
            'billing phone',
        ),
        new MedicalInformation(
            false, null, false, null, false, null, false, null, false, null,
            null, null, null,
        ),
        new TransportInformation(false),
        false,
        new DateTimeImmutable('2026-08-01 10:00:00+00:00'),
        $submittedAt,
        $status === EnrollmentStatus::Completed
            ? new DateTimeImmutable('2026-08-20 13:00:00+00:00')
            : null,
        $status === EnrollmentStatus::Cancelled
            ? new DateTimeImmutable('2026-08-20 13:00:00+00:00')
            : null,
    );
}

function e012CompleteFamily(bool $activeStudentMembership = true): Family
{
    $startedAt = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
    $endedAt = $activeStudentMembership
        ? null
        : new DateTimeImmutable('2026-08-20 00:00:00+00:00');
    $addresses = $activeStudentMembership ? [new FamilyAddress(
        new FamilyAddressId(101),
        new AddressLabel('HOME'),
        new Address('Current street', null, null, null, null, null),
        FamilyResourceStatus::Active,
    )] : [];
    $studentAddresses = $activeStudentMembership ? [new StudentAddressAssignment(
        new StudentAddressAssignmentId(102),
        new FamilyAddressId(101),
        new FamilyStudentId(44),
        $startedAt,
        null,
    )] : [];
    $emergencyContacts = $activeStudentMembership ? [new FamilyEmergencyContact(
        new FamilyEmergencyContactId(201),
        new FamilyResourceName('Emergency Contact'),
        new RelationshipTypeId(1),
        new EmergencyContactInformation('emergency mobile', null, null, null),
        FamilyResourceStatus::Active,
    )] : [];
    $emergencyAssignments = $activeStudentMembership ? [new EmergencyContactAssignment(
        new EmergencyContactAssignmentId(202),
        new FamilyEmergencyContactId(201),
        new FamilyStudentId(44),
        new EmergencyContactPriority(1),
        $startedAt,
        null,
    )] : [];
    $pickups = $activeStudentMembership ? [new FamilyAuthorizedPickup(
        new FamilyAuthorizedPickupId(301),
        new FamilyResourceName('Authorized Pickup'),
        new RelationshipTypeId(1),
        new AuthorizedPickupInformation('pickup mobile', null, null),
        null,
        FamilyResourceStatus::Active,
    )] : [];
    $pickupAssignments = $activeStudentMembership ? [new AuthorizedPickupAssignment(
        new AuthorizedPickupAssignmentId(302),
        new FamilyAuthorizedPickupId(301),
        new FamilyStudentId(44),
        $startedAt,
        null,
    )] : [];

    return Family::reconstitute(
        new FamilyId(77),
        new DisplayName('Authorized Family'),
        FamilyStatus::Active,
        [new FamilyRepresentative(
            new FamilyRepresentativeId(771),
            new RepresentativeId(33),
            new RelationshipTypeId(1),
            true,
            $startedAt,
            null,
        )],
        [new FamilyStudent(
            new FamilyStudentMembershipId(772),
            new FamilyStudentId(44),
            $startedAt,
            $endedAt,
        )],
        $addresses,
        [],
        $studentAddresses,
        $emergencyContacts,
        $emergencyAssignments,
        $pickups,
        $pickupAssignments,
    );
}
