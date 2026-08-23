<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\RepresentativeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use DateTimeImmutable;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerEnrollmentDomainTests(TestRunner $runner): void
{
    $runner->add('Enrollment identities accept only positive persisted values', function (): void {
        $classes = [
            EnrollmentId::class, StudentId::class, FamilyId::class, AcademicPeriodId::class,
            GradeId::class, SectionId::class, IdentificationTypeId::class, RepresentativeId::class,
        ];

        foreach ($classes as $class) {
            $identity = new $class(7);
            assertSameValue(7, $identity->value());
            assertSameValue(true, $identity->equals(new $class(7)));
            assertThrows(static fn () => new $class(0), InvalidEnrollmentState::class);
            assertThrows(static fn () => new $class(-1), InvalidEnrollmentState::class);
        }
        assertSameValue(false, (new StudentId(7))->equals(new FamilyId(7)));
    });

    $runner->add('New Enrollment starts as an incomplete Draft with immutable ownership', function (): void {
        $startedAt = enrollmentInstant('2026-08-15 09:10:11.123456');
        $enrollment = enrollmentDraft($startedAt);
        $reflection = new ReflectionClass($enrollment);

        assertSameValue(null, $enrollment->id());
        assertSameValue(EnrollmentStatus::Draft, $enrollment->status());
        assertSameValue([10, 20, 30], [
            $enrollment->studentId()->value(),
            $enrollment->familyId()->value(),
            $enrollment->academicPeriodId()->value(),
        ]);
        assertSameValue(true, $enrollment->startedAt() === $startedAt);
        assertSameValue(null, $enrollment->academicPlacement());
        assertSameValue(null, $enrollment->billingInformation());
        assertSameValue(null, $enrollment->medicalInformation());
        assertSameValue(null, $enrollment->transportInformation());
        assertSameValue(false, $enrollment->isAuthorizedToLeaveAlone());
        assertSameValue(null, $enrollment->submittedAt());
        assertSameValue(null, $enrollment->completedAt());
        assertSameValue(null, $enrollment->cancelledAt());

        foreach (['id', 'studentId', 'familyId', 'academicPeriodId', 'startedAt'] as $property) {
            assertSameValue(true, $reflection->getProperty($property)->isReadOnly());
        }
        foreach (['changeStudent', 'changeFamily', 'changeAcademicPeriod', 'changeStatus', 'setId'] as $method) {
            assertSameValue(false, method_exists($enrollment, $method));
        }
    });

    $runner->add('EnrollmentStatus contains exactly the approved lifecycle states', function (): void {
        assertSameValue(
            ['DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'],
            array_map(static fn (EnrollmentStatus $status): string => $status->value, EnrollmentStatus::cases()),
        );
    });

    $runner->add('AcademicPlacement requires positive Grade and permits an optional Section', function (): void {
        $withoutSection = new AcademicPlacement(new GradeId(1), null);
        $withSection = new AcademicPlacement(new GradeId(1), new SectionId(2));

        assertSameValue(null, $withoutSection->sectionId());
        assertSameValue(2, $withSection->sectionId()?->value());
        assertSameValue(true, $withSection->equals(new AcademicPlacement(new GradeId(1), new SectionId(2))));
    });

    $runner->add('Billing Medical and Transport preserve their approved invariants', function (): void {
        $billing = enrollmentBillingInformation();
        assertSameValue('Familia Núñez', $billing->legalName());
        assertSameValue('familia@example.test', $billing->billingEmail());
        assertThrows(
            static fn () => new BillingInformation(
                new IdentificationTypeId(1), '1', 'Name', 'Address', 'invalid', 'Phone'
            ),
            InvalidEnrollmentState::class,
        );

        $arguments = enrollmentMedicalArguments();
        $arguments[0] = true;
        assertThrows(static fn () => new MedicalInformation(...$arguments), InvalidEnrollmentState::class);
        assertSameValue(false, enrollmentMedicalInformation()->hasMedicalCondition());
        assertSameValue(true, (new TransportInformation(true))->requiresInstitutionalTransport());
        assertSameValue(false, (new TransportInformation(false))->requiresInstitutionalTransport());
    });

    $runner->add('Draft operations replace only complete annual values', function (): void {
        $enrollment = enrollmentDraft();
        $placement = new AcademicPlacement(new GradeId(4), new SectionId(8));
        $billing = enrollmentBillingInformation();
        $medical = enrollmentMedicalInformation();
        $transport = new TransportInformation(false);

        $enrollment->updateAcademicPlacement($placement);
        $enrollment->updateBillingInformation($billing);
        $enrollment->updateMedicalInformation($medical);
        $enrollment->updateTransportInformation($transport);
        $enrollment->updateLeaveAloneAuthorization(true);

        assertSameValue(true, $enrollment->academicPlacement() === $placement);
        assertSameValue(true, $enrollment->billingInformation() === $billing);
        assertSameValue(true, $enrollment->medicalInformation() === $medical);
        assertSameValue(true, $enrollment->transportInformation() === $transport);
        assertSameValue(true, $enrollment->isAuthorizedToLeaveAlone());
    });

    $runner->add('Submission records only status and SubmittedAt without a snapshot model', function (): void {
        $enrollment = enrollmentDraft();
        $submittedAt = enrollmentInstant('2026-08-15 10:00:00.123456');

        $enrollment->submit($submittedAt);

        assertSameValue(EnrollmentStatus::Submitted, $enrollment->status());
        assertSameValue(true, $enrollment->submittedAt() === $submittedAt);
        assertSameValue(false, method_exists($enrollment, 'submissionSnapshot'));
        assertThrows(static fn () => $enrollment->submit(enrollmentInstant('2026-08-15 11:00:00')), InvalidEnrollmentState::class);
    });

    $runner->add('Submitted Completed and Cancelled Enrollment annual data is immutable', function (): void {
        $submitted = enrollmentDraft();
        $submitted->submit(enrollmentInstant('2026-08-15 10:00:00'));
        $completed = enrollmentDraft();
        $completed->submit(enrollmentInstant('2026-08-15 10:00:00'));
        $completed->complete(enrollmentInstant('2026-08-15 11:00:00'));
        $cancelled = enrollmentDraft();
        $cancelled->cancel(enrollmentInstant('2026-08-15 10:00:00'));

        foreach ([$submitted, $completed, $cancelled] as $enrollment) {
            assertThrows(
                static fn () => $enrollment->updateAcademicPlacement(new AcademicPlacement(new GradeId(1), null)),
                InvalidEnrollmentState::class,
            );
            assertThrows(static fn () => $enrollment->updateBillingInformation(enrollmentBillingInformation()), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateMedicalInformation(enrollmentMedicalInformation()), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateTransportInformation(new TransportInformation(true)), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateLeaveAloneAuthorization(true), InvalidEnrollmentState::class);
        }
    });

    $runner->add('Reopening and resubmission preserve annual ownership and replace SubmittedAt', function (): void {
        $enrollment = enrollmentDraft();
        $placement = new AcademicPlacement(new GradeId(2), null);
        $enrollment->updateAcademicPlacement($placement);
        $enrollment->submit(enrollmentInstant('2026-08-15 10:00:00'));

        $enrollment->reopen();
        assertSameValue(EnrollmentStatus::Draft, $enrollment->status());
        assertSameValue(true, $enrollment->academicPlacement() === $placement);
        $enrollment->updateLeaveAloneAuthorization(true);
        $enrollment->submit(enrollmentInstant('2026-08-15 11:00:00'));

        assertSameValue('2026-08-15 11:00:00', $enrollment->submittedAt()?->format('Y-m-d H:i:s'));
        assertSameValue(true, $enrollment->isAuthorizedToLeaveAlone());
    });

    $runner->add('Completion cancellation and lifecycle timestamps follow the approved graph', function (): void {
        $completed = enrollmentDraft();
        assertThrows(static fn () => $completed->complete(enrollmentInstant('2026-08-15 10:00:00')), InvalidEnrollmentState::class);
        $completed->submit(enrollmentInstant('2026-08-15 10:00:00'));
        $completed->complete(enrollmentInstant('2026-08-15 11:00:00'));
        assertSameValue(EnrollmentStatus::Completed, $completed->status());
        assertThrows(static fn () => $completed->reopen(), InvalidEnrollmentState::class);

        $cancelled = enrollmentDraft();
        $cancelled->cancel(enrollmentInstant('2026-08-15 10:00:00'));
        assertSameValue(EnrollmentStatus::Cancelled, $cancelled->status());
        assertThrows(static fn () => $cancelled->cancel(enrollmentInstant('2026-08-15 11:00:00')), InvalidEnrollmentState::class);

        $beforeStart = enrollmentDraft(enrollmentInstant('2026-08-15 09:00:00'));
        assertThrows(static fn () => $beforeStart->submit(enrollmentInstant('2026-08-15 08:59:59')), InvalidEnrollmentState::class);
    });

    $runner->add('Reconstitution accepts coherent states and rejects missing lifecycle evidence', function (): void {
        $draft = reconstitutedEnrollment(EnrollmentStatus::Draft, false);
        $submitted = reconstitutedEnrollment(EnrollmentStatus::Submitted, true);
        $completed = reconstitutedEnrollment(EnrollmentStatus::Completed, true);
        $cancelled = reconstitutedEnrollment(EnrollmentStatus::Cancelled, false);

        assertSameValue(90, $draft->id()?->value());
        assertSameValue(EnrollmentStatus::Submitted, $submitted->status());
        assertSameValue(true, $completed->completedAt() !== null);
        assertSameValue(true, $cancelled->cancelledAt() !== null);

        assertThrows(
            static fn () => Enrollment::reconstitute(
                new EnrollmentId(1), new StudentId(2), new FamilyId(3), new AcademicPeriodId(4),
                EnrollmentStatus::Submitted, null, null, null, null, false,
                enrollmentInstant('2026-08-15 09:00:00'), null, null, null,
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Enrollment Domain has no snapshot model and stays isolated', function (): void {
        $directory = __DIR__ . '/../app/Enrollment/Domain';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];
        $source = '';
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        sort($files, SORT_STRING);
        assertSameValue(19, count($files));
        foreach (['SubmissionSnapshot', 'App\\Family\\', 'App\\Student\\', 'App\\Representative\\', 'PDO', 'SELECT ', 'INSERT ', 'Controller', 'Request', 'Response', 'Session'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        assertSameValue(true, interface_exists(\App\Enrollment\Domain\EnrollmentRepository::class));
    });
}

function enrollmentDraft(?DateTimeImmutable $startedAt = null): Enrollment
{
    return Enrollment::startDraft(
        new StudentId(10),
        new FamilyId(20),
        new AcademicPeriodId(30),
        $startedAt ?? enrollmentInstant('2026-08-15 09:00:00'),
    );
}

function enrollmentBillingInformation(): BillingInformation
{
    return new BillingInformation(
        new IdentificationTypeId(5),
        ' 0912345678 ',
        ' Familia Núñez ',
        ' Av. República 123 ',
        ' familia@example.test ',
        ' +593 99 000 0000 ',
    );
}

/** @return array{bool, ?string, bool, ?string, bool, ?string, bool, ?string, bool, ?string, ?string, ?string, ?string} */
function enrollmentMedicalArguments(): array
{
    return [false, null, false, null, false, null, false, null, false, null, null, null, null];
}

function enrollmentMedicalInformation(): MedicalInformation
{
    return new MedicalInformation(...enrollmentMedicalArguments());
}

function reconstitutedEnrollment(EnrollmentStatus $status, bool $hasSubmission): Enrollment
{
    $submittedAt = $hasSubmission ? enrollmentInstant('2026-08-15 10:00:00') : null;

    return Enrollment::reconstitute(
        new EnrollmentId(90),
        new StudentId(10),
        new FamilyId(20),
        new AcademicPeriodId(30),
        $status,
        new AcademicPlacement(new GradeId(1), null),
        enrollmentBillingInformation(),
        enrollmentMedicalInformation(),
        new TransportInformation(false),
        false,
        enrollmentInstant('2026-08-15 09:00:00'),
        $submittedAt,
        $status === EnrollmentStatus::Completed ? enrollmentInstant('2026-08-15 11:00:00') : null,
        $status === EnrollmentStatus::Cancelled ? enrollmentInstant('2026-08-15 12:00:00') : null,
    );
}

function enrollmentInstant(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value . (str_contains($value, '+') ? '' : '+00:00'));
}
