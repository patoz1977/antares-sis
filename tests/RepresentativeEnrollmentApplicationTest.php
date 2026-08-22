<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\RepresentativePortal\Dto\ResolveOrStartRepresentativeEnrollmentInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeContactInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEmploymentInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentBillingInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentLeaveAloneInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentMedicalInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentTransportInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativePersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateStudentPersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextChanged;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentFamilySelectionRequired;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable;
use App\Enrollment\Application\RepresentativePortal\GetRepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentPortalAuthorization;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentSectionStatus;
use App\Enrollment\Application\RepresentativePortal\ResolveOrStartRepresentativeEnrollment;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeContactInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeEmploymentInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativePersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthorizedStudentPersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentBillingInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentLeaveAloneAuthorization;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentMedicalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentTransportInformation;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Family\Application\GetFamilyResources;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId as FamilyRepresentativeMembershipId;
use App\Family\Domain\ValueObject\FamilyStudentId as FamilyStudentMembershipId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;
use App\Person\Domain\PersonStatus;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use Tests\Support\TestRunner;

function registerRepresentativeEnrollmentApplicationTests(TestRunner $runner): void
{
    $runner->add('E011 portal read context derives Representative Family active Students and period server-side', function (): void {
        $fixture = e011PortalFixture();
        $context = $fixture['authorization']->resolveReadContext(44);

        assertSameValue(33, $context->representativeId);
        assertSameValue(77, $context->familyId);
        assertSameValue(5, $context->academicPeriod?->id);
        assertSameValue([44], array_map(static fn ($option): int => $option->student->id, $context->students));
        assertSameValue(true, $context->acknowledgementsSatisfied);
        assertThrows(
            static fn () => $fixture['authorization']->resolveReadContext(999),
            RepresentativeEnrollmentStudentUnavailable::class,
        );
    });

    $runner->add('E011 portal fails closed without actor role or Family and requires the existing multi-Family selection', function (): void {
        foreach ([
            e011PortalFixture(authenticated: false),
            e011PortalFixture(representativeExists: false),
            e011PortalFixture(familyCount: 0),
        ] as $fixture) {
            assertThrows(
                static fn () => $fixture['authorization']->resolveReadContext(),
                RepresentativeEnrollmentContextUnavailable::class,
            );
        }
        $multiple = e011PortalFixture(familyCount: 2);
        assertThrows(
            static fn () => $multiple['authorization']->resolveReadContext(),
            RepresentativeEnrollmentFamilySelectionRequired::class,
        );
    });

    $runner->add('E011 portal state represents absent active period without creating Enrollment', function (): void {
        $fixture = e011PortalFixture(periodActive: false);
        $state = $fixture['state']->handle(44);

        assertSameValue(false, $state->enrollmentAvailable);
        assertSameValue(false, $state->maintenanceEnabled);
        assertSameValue(null, $state->enrollment);
        assertSameValue(0, $fixture['enrollments']->saveCalls);
    });

    $runner->add('E011 portal state exposes pending acknowledgements and strict mutations reject them', function (): void {
        $fixture = e011PortalFixture(acknowledgementsSatisfied: false);
        $state = $fixture['state']->handle(44);

        assertSameValue(false, $state->maintenanceEnabled);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Pending, $state->progress->acknowledgements);
        assertThrows(
            static fn () => $fixture['personal']->handle(new UpdateRepresentativePersonalInformationInput(
                77, 5, 'Blocked', null, 'Representative', null,
                new DateTimeImmutable('1990-01-01'), null, null,
            )),
            RepresentativeAcknowledgementsRequired::class,
        );
    });

    $runner->add('E011 resolve-or-start reuses one transaction and returns the current Enrollment', function (): void {
        $fixture = e011PortalFixture();
        $input = new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44);
        $created = $fixture['resolveOrStart']->handle($input);
        $resolved = $fixture['resolveOrStart']->handle($input);

        assertSameValue($created->id, $resolved->id);
        assertSameValue(44, $created->studentId);
        assertSameValue(77, $created->familyId);
        assertSameValue(5, $created->academicPeriodId);
        assertSameValue(1, $fixture['enrollments']->saveCalls);
        assertSameValue(false, in_array('begin-nested', $fixture['transactions']->events, true));
    });

    $runner->add('E011 resolve-or-start rejects stale Family and AcademicPeriod proposals', function (): void {
        $fixture = e011PortalFixture();

        assertThrows(
            static fn () => $fixture['resolveOrStart']->handle(
                new ResolveOrStartRepresentativeEnrollmentInput(78, 5, 44)
            ),
            RepresentativeEnrollmentContextChanged::class,
        );
        assertThrows(
            static fn () => $fixture['resolveOrStart']->handle(
                new ResolveOrStartRepresentativeEnrollmentInput(77, 6, 44)
            ),
            RepresentativeEnrollmentContextChanged::class,
        );
    });

    $runner->add('E011 non-Draft current Enrollment is readonly and rejects annual maintenance', function (): void {
        $fixture = e011PortalFixture();
        $fixture['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));
        $enrollment = $fixture['enrollments']->findByStudentAndAcademicPeriod(
            new \App\Enrollment\Domain\ValueObject\StudentId(44),
            new \App\Enrollment\Domain\ValueObject\AcademicPeriodId(5),
        ) ?? throw new \RuntimeException('E011 readonly fixture Enrollment is unavailable.');
        $enrollment->cancel(new DateTimeImmutable('2026-08-21 12:14:00+00:00'));
        $fixture['enrollments']->save($enrollment);

        assertSameValue(true, $fixture['state']->handle(44)->readOnly);
        assertThrows(
            static fn () => $fixture['leave']->handle(
                new UpdateRepresentativeEnrollmentLeaveAloneInput(77, 5, 44, true)
            ),
            \App\Enrollment\Domain\Exception\InvalidEnrollmentState::class,
        );
    });

    $runner->add('E011 Representative self-service updates only approved Person and employment fields', function (): void {
        $fixture = e011PortalFixture();
        $personal = $fixture['personal']->handle(new UpdateRepresentativePersonalInformationInput(
            77, 5, 'Updated', 'Middle', 'Representative', 'Second',
            new DateTimeImmutable('1991-02-03'), 8, 9,
        ));
        $contact = $fixture['contact']->handle(new UpdateRepresentativeContactInformationInput(
            77, 5, 'new@example.test', null, 'landline',
        ));
        $employment = $fixture['employment']->handle(new UpdateRepresentativeEmploymentInformationInput(
            77, 5, 'Engineer', 'Company', 'Lead', 'work phone', 'work@example.test',
        ));

        assertSameValue('Updated', $personal->firstName);
        assertSameValue('DOC-REP', $personal->documentNumber);
        assertSameValue(3, $personal->sexId);
        assertSameValue('stored@example.test', $personal->email);
        assertSameValue('Updated', $contact->firstName);
        assertSameValue('new@example.test', $contact->email);
        assertSameValue(PersonStatus::Active, $contact->status);
        assertSameValue('Engineer', $employment->occupation);
        assertSameValue(RepresentativeStatus::Active, $employment->status);
    });

    $runner->add('E011 Student personal mutation derives Person and preserves Student and restricted identity state', function (): void {
        $fixture = e011PortalFixture();
        $beforeStudent = $fixture['students']->findById(new \App\Student\Domain\ValueObject\StudentId(44));
        $updated = $fixture['studentPersonal']->handle(new UpdateStudentPersonalInformationInput(
            77, 5, 44, 'Updated', null, 'Student', null,
            new DateTimeImmutable('2012-04-05'), null, 9,
        ));
        $afterStudent = $fixture['students']->findById(new \App\Student\Domain\ValueObject\StudentId(44));

        assertSameValue('Updated', $updated->firstName);
        assertSameValue('DOC-STUDENT', $updated->documentNumber);
        assertSameValue(3, $updated->sexId);
        assertSameValue($beforeStudent?->institutionalCode()->value(), $afterStudent?->institutionalCode()->value());
        assertSameValue($beforeStudent?->admissionDate()->value(), $afterStudent?->admissionDate()->value());
        assertSameValue($beforeStudent?->status(), $afterStudent?->status());
    });

    $runner->add('E011 annual wrappers resolve Enrollment without browser-authoritative EnrollmentId', function (): void {
        $fixture = e011PortalFixture();
        $fixture['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));
        $billing = $fixture['billing']->handle(new UpdateRepresentativeEnrollmentBillingInput(
            77, 5, 44, 1, 'BILL-1', 'Legal Name', 'Billing Address', 'billing@example.test', 'phone',
        ));
        $medical = $fixture['medical']->handle(new UpdateRepresentativeEnrollmentMedicalInput(
            77, 5, 44, false, null, false, null, false, null, false, null,
            false, null, null, null, null,
        ));
        $transport = $fixture['transport']->handle(new UpdateRepresentativeEnrollmentTransportInput(
            77, 5, 44, false,
        ));
        $leave = $fixture['leave']->handle(new UpdateRepresentativeEnrollmentLeaveAloneInput(
            77, 5, 44, true,
        ));

        assertSameValue('BILL-1', $billing->billingInformation?->identificationNumber);
        assertSameValue(false, $medical->medicalInformation?->hasMedicalCondition);
        assertSameValue(false, $transport->transportInformation?->requiresInstitutionalTransport);
        assertSameValue(true, $leave->isAuthorizedToLeaveAlone);
        assertSameValue(5, $fixture['enrollments']->saveCalls);
    });

    $runner->add('E011 advisory progress is derived and never persisted as submission authority', function (): void {
        $fixture = e011PortalFixture();
        $fixture['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));
        $before = $fixture['state']->handle(44);
        $fixture['billing']->handle(new UpdateRepresentativeEnrollmentBillingInput(
            77, 5, 44, 1, 'BILL-2', 'Legal Name', 'Billing Address', 'billing@example.test', 'phone',
        ));
        $fixture['leave']->handle(new UpdateRepresentativeEnrollmentLeaveAloneInput(77, 5, 44, true));
        $after = $fixture['state']->handle(44);

        assertSameValue(RepresentativeEnrollmentSectionStatus::Pending, $before->progress->billing);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Complete, $after->progress->billing);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Pending, $after->progress->medical);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Pending, $after->progress->transport);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Complete, $after->progress->pickupOrLeaveAlone);
        assertSameValue(RepresentativeEnrollmentSectionStatus::Complete, $after->progress->employment);
        assertSameValue(false, $after->enrollment?->hasSubmissionSnapshot);
    });

    $runner->add('E011 Phase 2 remains Application-only and excludes lifecycle and Delivery behavior', function (): void {
        $directory = dirname(__DIR__) . '/app/Enrollment/Application/RepresentativePortal';
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Controller', 'SessionManager',
            'SubmissionSnapshot', '->submit(', '->reopen(', '->complete(', '->cancel('] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        assertSameValue(false, is_dir(dirname(__DIR__) . '/app/Enrollment/Http/RepresentativePortal'));
    });
}

/** @return array<string, mixed> */
function e011PortalFixture(
    bool $acknowledgementsSatisfied = true,
    bool $periodActive = true,
    bool $authenticated = true,
    bool $representativeExists = true,
    int $familyCount = 1,
): array {
    $access = familyContextAuthorizationFixture($authenticated, $representativeExists);
    $families = $access['families'];
    if ($familyCount >= 1) {
        $families->seed(e011PortalFamily());
    }
    if ($familyCount >= 2) {
        $families->seed(e011PortalFamily(78, 45));
    }

    $persons = new InMemoryPersonApplicationRepository(applicationToday());
    $persons->seed(applicationPerson(22, 'DOC-REP'));
    $persons->seed(applicationPerson(10, 'DOC-STUDENT'));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $representatives->seed(new Representative(
        new RepresentativeId(33),
        new RepresentativePersonId(22),
        null,
        RepresentativeStatus::Active,
    ));
    $students = new InMemoryStudentApplicationRepository();
    $students->seed(e010Student(44));

    $periods = $periodActive
        ? [representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Active)]
        : [];
    $requirements = $acknowledgementsSatisfied
        ? []
        : [representativeAcknowledgementRequirement(51)];
    $acknowledgements = representativeAcknowledgementTestServices(
        $access['getRepresentative'],
        $requirements,
        $periods,
    );
    $satisfaction = new CheckInstitutionalAcknowledgementSatisfaction(
        $acknowledgements['requirements'],
        $acknowledgements['completions'],
    );
    $authorization = new RepresentativeEnrollmentPortalAuthorization(
        $access['resolve'],
        new GetActiveAcademicPeriod($acknowledgements['periods']),
        $acknowledgements['periods'],
        $families,
        $students,
        $persons,
        $satisfaction,
        $acknowledgements['gate'],
    );
    $transactions = new E010FakeTransactionRunner();
    $enrollments = new E010InMemoryEnrollmentRepository($transactions);
    $clock = new E010FakeClock(new DateTimeImmutable('2026-08-21 12:13:14+00:00'));
    $initializer = new EnrollmentDraftInitializer(
        $students,
        $families,
        $acknowledgements['periods'],
        $enrollments,
        e010AcademicReferences(),
        $clock,
    );
    $mutations = new RepresentativeEnrollmentMutationSupport($authorization, $enrollments, $transactions);

    return [
        'authorization' => $authorization,
        'state' => new GetRepresentativeEnrollmentPortalState(
            $authorization,
            $persons,
            $representatives,
            $enrollments,
            new GetFamilyResources($families),
        ),
        'resolveOrStart' => new ResolveOrStartRepresentativeEnrollment(
            $authorization, $enrollments, $initializer, $transactions,
        ),
        'personal' => new UpdateAuthenticatedRepresentativePersonalInformation(
            $authorization, $persons, $clock, $transactions,
        ),
        'contact' => new UpdateAuthenticatedRepresentativeContactInformation(
            $authorization, $persons, $transactions,
        ),
        'employment' => new UpdateAuthenticatedRepresentativeEmploymentInformation(
            $authorization, $representatives, $transactions,
        ),
        'studentPersonal' => new UpdateAuthorizedStudentPersonalInformation(
            $authorization, $persons, $clock, $transactions,
        ),
        'billing' => new UpdateRepresentativeEnrollmentBillingInformation($mutations),
        'medical' => new UpdateRepresentativeEnrollmentMedicalInformation($mutations),
        'transport' => new UpdateRepresentativeEnrollmentTransportInformation($mutations),
        'leave' => new UpdateRepresentativeEnrollmentLeaveAloneAuthorization($mutations),
        'persons' => $persons,
        'representatives' => $representatives,
        'students' => $students,
        'families' => $families,
        'enrollments' => $enrollments,
        'transactions' => $transactions,
    ];
}

function e011PortalFamily(int $familyId = 77, int $studentId = 44): Family
{
    return Family::reconstitute(
        new FamilyId($familyId),
        new DisplayName('Authorized Family'),
        FamilyStatus::Active,
        [new FamilyRepresentative(
            new FamilyRepresentativeMembershipId($familyId * 10 + 1),
            new FamilyRepresentativeId(33),
            new RelationshipTypeId(1),
            true,
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            null,
        )],
        [new FamilyStudent(
            new FamilyStudentMembershipId($familyId * 10 + 2),
            new FamilyStudentId($studentId),
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            null,
        )],
    );
}
