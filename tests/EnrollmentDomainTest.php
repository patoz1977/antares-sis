<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\EnrollmentSubmissionSnapshot;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\SubmittedAddressSnapshot;
use App\Enrollment\Domain\SubmittedAuthorizedPickupSnapshot;
use App\Enrollment\Domain\SubmittedEmergencyContactSnapshot;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\EnrollmentSubmissionSnapshotId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\Geolocation;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\RepresentativeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Domain\ValueObject\SubmittedAddressSnapshotId;
use App\Enrollment\Domain\ValueObject\SubmittedAuthorizedPickupSnapshotId;
use App\Enrollment\Domain\ValueObject\SubmittedEmergencyContactSnapshotId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use DateTimeImmutable;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerEnrollmentDomainTests(TestRunner $runner): void
{
    $runner->add('Enrollment identities accept only positive persisted values', function (): void {
        $classes = [
            EnrollmentId::class,
            StudentId::class,
            FamilyId::class,
            AcademicPeriodId::class,
            GradeId::class,
            SectionId::class,
            IdentificationTypeId::class,
            RepresentativeId::class,
            EnrollmentSubmissionSnapshotId::class,
            SubmittedAddressSnapshotId::class,
            SubmittedEmergencyContactSnapshotId::class,
            SubmittedAuthorizedPickupSnapshotId::class,
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
        $startedAt = new DateTimeImmutable('2026-08-15 09:10:11.123456+00:00');
        $enrollment = enrollmentDraft($startedAt);
        $reflection = new ReflectionClass($enrollment);

        assertSameValue(null, $enrollment->id());
        assertSameValue(EnrollmentStatus::Draft, $enrollment->status());
        assertSameValue(10, $enrollment->studentId()->value());
        assertSameValue(20, $enrollment->familyId()->value());
        assertSameValue(30, $enrollment->academicPeriodId()->value());
        assertSameValue(true, $enrollment->startedAt() === $startedAt);
        assertSameValue(null, $enrollment->academicPlacement());
        assertSameValue(null, $enrollment->billingInformation());
        assertSameValue(null, $enrollment->medicalInformation());
        assertSameValue(null, $enrollment->transportInformation());
        assertSameValue(false, $enrollment->isAuthorizedToLeaveAlone());
        assertSameValue(null, $enrollment->submissionSnapshot());
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

    $runner->add('New Enrollment accepts complete optional annual values without changing Draft semantics', function (): void {
        $placement = new AcademicPlacement(new GradeId(1), new SectionId(2));
        $billing = billingInformation();
        $medical = medicalInformation();
        $transport = new TransportInformation(true);
        $enrollment = Enrollment::startDraft(
            new StudentId(10),
            new FamilyId(20),
            new AcademicPeriodId(30),
            instant('2026-08-15 09:00:00'),
            $placement,
            $billing,
            $medical,
            $transport,
            true,
        );

        assertSameValue(EnrollmentStatus::Draft, $enrollment->status());
        assertSameValue(true, $enrollment->academicPlacement() === $placement);
        assertSameValue(true, $enrollment->billingInformation() === $billing);
        assertSameValue(true, $enrollment->medicalInformation() === $medical);
        assertSameValue(true, $enrollment->transportInformation() === $transport);
        assertSameValue(true, $enrollment->isAuthorizedToLeaveAlone());
    });

    $runner->add('EnrollmentStatus contains exactly the approved lifecycle states', function (): void {
        assertSameValue(
            ['DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'],
            array_map(static fn (EnrollmentStatus $status): string => $status->value, EnrollmentStatus::cases()),
        );
    });

    $runner->add('AcademicPlacement requires positive Grade and permits an optional positive Section', function (): void {
        $withoutSection = new AcademicPlacement(new GradeId(1), null);
        $withSection = new AcademicPlacement(new GradeId(1), new SectionId(2));

        assertSameValue(1, $withoutSection->gradeId()->value());
        assertSameValue(null, $withoutSection->sectionId());
        assertSameValue(2, $withSection->sectionId()?->value());
        assertSameValue(true, $withSection->equals(new AcademicPlacement(new GradeId(1), new SectionId(2))));
        assertThrows(static fn (): GradeId => new GradeId(0), InvalidEnrollmentState::class);
        assertThrows(static fn (): SectionId => new SectionId(0), InvalidEnrollmentState::class);
    });

    $runner->add('BillingInformation is complete normalized and preserves UTF-8', function (): void {
        $billing = billingInformation();

        assertSameValue(5, $billing->identificationTypeId()->value());
        assertSameValue('0912345678', $billing->identificationNumber());
        assertSameValue('Familia Núñez', $billing->legalName());
        assertSameValue('Av. República 123', $billing->billingAddress());
        assertSameValue('familia@example.test', $billing->billingEmail());
        assertSameValue('+593 99 000 0000', $billing->phone());
    });

    $runner->add('BillingInformation rejects every blank field invalid email and excess length', function (): void {
        $valid = ['0912345678', 'Familia', 'Dirección', 'family@example.test', '0990000000'];

        for ($index = 0; $index < count($valid); $index++) {
            $fields = $valid;
            $fields[$index] = ' ';
            assertThrows(
                static fn (): BillingInformation => new BillingInformation(new IdentificationTypeId(1), ...$fields),
                InvalidEnrollmentState::class,
            );
        }
        assertThrows(
            static fn (): BillingInformation => new BillingInformation(
                new IdentificationTypeId(1),
                '0912345678',
                'Familia',
                'Dirección',
                'invalid-email',
                '0990000000',
            ),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn (): BillingInformation => new BillingInformation(
                new IdentificationTypeId(1),
                str_repeat('x', 51),
                'Familia',
                'Dirección',
                'family@example.test',
                '0990000000',
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('MedicalInformation enforces each approved true-detail dependency', function (): void {
        for ($flag = 0; $flag < 5; $flag++) {
            $arguments = medicalArguments();
            $arguments[$flag * 2] = true;
            $arguments[$flag * 2 + 1] = null;
            assertThrows(
                static fn (): MedicalInformation => new MedicalInformation(...$arguments),
                InvalidEnrollmentState::class,
            );
        }
    });

    $runner->add('MedicalInformation accepts false answers optional values and UTF-8 details', function (): void {
        $medical = new MedicalInformation(
            true,
            'Condición respiratoria',
            false,
            null,
            true,
            'Medicamento pediátrico',
            false,
            null,
            true,
            'Seguro Médico Ñandú',
            'Dra. Gómez',
            'extensión pediátrica',
            'Observación clínica UTF-8',
        );

        assertSameValue(true, $medical->hasMedicalCondition());
        assertSameValue('Condición respiratoria', $medical->medicalConditionDetail());
        assertSameValue(false, $medical->hasAllergies());
        assertSameValue(null, $medical->allergyDetail());
        assertSameValue('Medicamento pediátrico', $medical->medicationName());
        assertSameValue('Seguro Médico Ñandú', $medical->insuranceProvider());
        assertSameValue('Dra. Gómez', $medical->pediatricianName());
        assertSameValue('extensión pediátrica', $medical->pediatricianPhone());
        assertSameValue('Observación clínica UTF-8', $medical->observations());
    });

    $runner->add('TransportInformation represents both explicit answers while Draft may omit it', function (): void {
        assertSameValue(true, (new TransportInformation(true))->requiresInstitutionalTransport());
        assertSameValue(false, (new TransportInformation(false))->requiresInstitutionalTransport());
        assertSameValue(null, enrollmentDraft()->transportInformation());
    });

    $runner->add('Draft operations replace complete annual values and leave-alone state', function (): void {
        $enrollment = enrollmentDraft();
        $placement = new AcademicPlacement(new GradeId(4), new SectionId(8));
        $billing = billingInformation();
        $medical = medicalInformation();
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

    $runner->add('Submitted Completed and Cancelled Enrollments reject every Draft edit', function (): void {
        $enrollments = [];
        $submitted = enrollmentDraft();
        $submitted->submit(newSubmissionSnapshot(), instant('2026-08-15 10:00:00'));
        $enrollments[] = $submitted;
        $completed = enrollmentDraft();
        $completed->submit(newSubmissionSnapshot(), instant('2026-08-15 10:00:00'));
        $completed->complete(instant('2026-08-15 11:00:00'));
        $enrollments[] = $completed;
        $cancelled = enrollmentDraft();
        $cancelled->cancel(instant('2026-08-15 10:00:00'));
        $enrollments[] = $cancelled;

        foreach ($enrollments as $enrollment) {
            assertThrows(
                static fn () => $enrollment->updateAcademicPlacement(new AcademicPlacement(new GradeId(1), null)),
                InvalidEnrollmentState::class,
            );
            assertThrows(static fn () => $enrollment->updateBillingInformation(billingInformation()), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateMedicalInformation(medicalInformation()), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateTransportInformation(new TransportInformation(true)), InvalidEnrollmentState::class);
            assertThrows(static fn () => $enrollment->updateLeaveAloneAuthorization(true), InvalidEnrollmentState::class);
        }
    });

    $runner->add('SubmittedAddressSnapshot normalizes approved fields and optional geolocation', function (): void {
        $address = SubmittedAddressSnapshot::create(
            '  Principal  ',
            '  Av. República  ',
            '  N42-10  ',
            '  Amazonas  ',
            '  Iñaquito  ',
            '  Frente al parque  ',
            new Geolocation('-0.1806532', '-78.4678382'),
        );

        assertSameValue(null, $address->id());
        assertSameValue('Principal', $address->label());
        assertSameValue('Av. República', $address->mainStreet());
        assertSameValue('N42-10', $address->streetNumber());
        assertSameValue('-0.1806532', $address->geolocation()?->latitude());
        assertSameValue('-78.4678382', $address->geolocation()?->longitude());
    });

    $runner->add('Address and Geolocation reject missing required text malformed or out-of-range coordinates', function (): void {
        assertThrows(
            static fn () => SubmittedAddressSnapshot::create(' ', 'Street', null, null, null, null, null),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn () => SubmittedAddressSnapshot::create('Home', ' ', null, null, null, null, null),
            InvalidEnrollmentState::class,
        );
        assertThrows(static fn (): Geolocation => new Geolocation('91', '0'), InvalidEnrollmentState::class);
        assertThrows(static fn (): Geolocation => new Geolocation('0', '-181'), InvalidEnrollmentState::class);
        assertThrows(static fn (): Geolocation => new Geolocation('not-a-number', '0'), InvalidEnrollmentState::class);
    });

    $runner->add('Emergency snapshot validates copied values priority sort order and optional email', function (): void {
        $contact = emergencyContact(2, 3);

        assertSameValue(null, $contact->id());
        assertSameValue('Contacto 2', $contact->names());
        assertSameValue('MOTHER', $contact->relationshipTypeCode());
        assertSameValue('Madre', $contact->relationshipTypeName());
        assertSameValue(3, $contact->priority());
        assertSameValue(2, $contact->sortOrder());
        assertThrows(static fn () => emergencyContact(0), InvalidEnrollmentState::class);
        assertThrows(static fn () => emergencyContact(1, 0), InvalidEnrollmentState::class);
        assertThrows(
            static fn () => SubmittedEmergencyContactSnapshot::create(
                'Names', 'CODE', 'Name', '099', null, 'invalid-email', null, null, 1,
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Authorized pickup snapshot copies relationship and document facts without live identities', function (): void {
        $pickup = authorizedPickup();

        assertSameValue(null, $pickup->id());
        assertSameValue('Persona Autorizada', $pickup->names());
        assertSameValue('UNCLE', $pickup->relationshipTypeCode());
        assertSameValue('Tío', $pickup->relationshipTypeName());
        assertSameValue('NATIONAL_ID', $pickup->documentTypeCode());
        assertSameValue('Cédula', $pickup->documentTypeName());
        assertSameValue('1712345678', $pickup->documentNumber());
        assertSameValue(false, method_exists($pickup, 'familyAuthorizedPickupId'));
    });

    $runner->add('New snapshot requires one address one or more emergencies and permits zero or many pickups', function (): void {
        $withoutPickups = newSubmissionSnapshot();
        $withPickups = newSubmissionSnapshot([authorizedPickup(), authorizedPickup('Otra Persona')]);

        assertSameValue(null, $withoutPickups->id());
        assertSameValue(null, $withoutPickups->address()->id());
        assertSameValue(1, count($withoutPickups->emergencyContacts()));
        assertSameValue(0, count($withoutPickups->authorizedPickups()));
        assertSameValue(2, count($withPickups->authorizedPickups()));
        assertThrows(
            static fn () => EnrollmentSubmissionSnapshot::create(
                new RepresentativeId(1),
                instant('2026-08-15 09:30:00'),
                newAddress(),
                [],
                [],
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Snapshot sorts emergency contacts deterministically and rejects duplicate sort order', function (): void {
        $snapshot = EnrollmentSubmissionSnapshot::create(
            new RepresentativeId(1),
            instant('2026-08-15 09:30:00'),
            newAddress(),
            [emergencyContact(3), emergencyContact(1), emergencyContact(2)],
            [],
        );

        assertSameValue([1, 2, 3], array_map(
            static fn (SubmittedEmergencyContactSnapshot $contact): int => $contact->sortOrder(),
            $snapshot->emergencyContacts(),
        ));
        assertThrows(
            static fn () => EnrollmentSubmissionSnapshot::create(
                new RepresentativeId(1),
                instant('2026-08-15 09:30:00'),
                newAddress(),
                [emergencyContact(1), emergencyContact(1)],
                [],
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Reconstituted snapshot requires persisted root and child identities', function (): void {
        $snapshot = persistedSubmissionSnapshot();

        assertSameValue(100, $snapshot->id()?->value());
        assertSameValue(101, $snapshot->address()->id()?->value());
        assertSameValue(102, $snapshot->emergencyContacts()[0]->id()?->value());
        assertSameValue(103, $snapshot->authorizedPickups()[0]->id()?->value());
        assertThrows(
            static fn () => EnrollmentSubmissionSnapshot::reconstitute(
                new EnrollmentSubmissionSnapshotId(1),
                new RepresentativeId(1),
                instant('2026-08-15 09:30:00'),
                newAddress(),
                [persistedEmergencyContact(2, 1)],
                [],
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Reconstituted snapshot rejects duplicate child identities', function (): void {
        assertThrows(
            static fn () => EnrollmentSubmissionSnapshot::reconstitute(
                new EnrollmentSubmissionSnapshotId(1),
                new RepresentativeId(1),
                instant('2026-08-15 09:30:00'),
                persistedAddress(),
                [persistedEmergencyContact(2, 1), persistedEmergencyContact(2, 2)],
                [],
            ),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn () => EnrollmentSubmissionSnapshot::reconstitute(
                new EnrollmentSubmissionSnapshotId(1),
                new RepresentativeId(1),
                instant('2026-08-15 09:30:00'),
                persistedAddress(),
                [persistedEmergencyContact(2, 1)],
                [persistedPickup(3), persistedPickup(3)],
            ),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Submission snapshot and historical children expose immutable state only', function (): void {
        $snapshot = persistedSubmissionSnapshot();

        foreach ([$snapshot, $snapshot->address(), ...$snapshot->emergencyContacts(), ...$snapshot->authorizedPickups()] as $object) {
            assertSameValue(true, (new ReflectionClass($object))->isReadOnly());
        }
        foreach (['replaceAddress', 'addEmergencyContact', 'removeEmergencyContact', 'addAuthorizedPickup'] as $method) {
            assertSameValue(false, method_exists($snapshot, $method));
        }
    });

    $runner->add('Submission atomically attaches one snapshot sets SubmittedAt and locks Draft editing', function (): void {
        $enrollment = enrollmentDraft();
        $snapshot = newSubmissionSnapshot();
        $submittedAt = instant('2026-08-15 10:00:00.123456');

        $enrollment->submit($snapshot, $submittedAt);

        assertSameValue(EnrollmentStatus::Submitted, $enrollment->status());
        assertSameValue(true, $enrollment->submissionSnapshot() === $snapshot);
        assertSameValue(true, $enrollment->submittedAt() === $submittedAt);
        assertThrows(
            static fn () => $enrollment->updateLeaveAloneAuthorization(true),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn () => $enrollment->submit(newSubmissionSnapshot(), instant('2026-08-15 11:00:00')),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Reopening preserves ownership annual data and prior snapshot then resubmission replaces it', function (): void {
        $enrollment = enrollmentDraft();
        $placement = new AcademicPlacement(new GradeId(2), null);
        $first = newSubmissionSnapshot();
        $second = newSubmissionSnapshot([authorizedPickup()]);
        $enrollment->updateAcademicPlacement($placement);
        $enrollment->submit($first, instant('2026-08-15 10:00:00'));

        $enrollment->reopen();
        assertSameValue(EnrollmentStatus::Draft, $enrollment->status());
        assertSameValue(true, $enrollment->academicPlacement() === $placement);
        assertSameValue(true, $enrollment->submissionSnapshot() === $first);
        assertSameValue(10, $enrollment->studentId()->value());
        $enrollment->updateLeaveAloneAuthorization(true);
        $enrollment->submit($second, instant('2026-08-15 11:00:00'));

        assertSameValue(true, $enrollment->submissionSnapshot() === $second);
        assertSameValue(1, count($enrollment->submissionSnapshot()?->authorizedPickups() ?? []));
    });

    $runner->add('Completion is only Submitted to Completed and preserves annual state and snapshot', function (): void {
        $enrollment = enrollmentDraft();
        $billing = billingInformation();
        $snapshot = newSubmissionSnapshot();
        $enrollment->updateBillingInformation($billing);
        assertThrows(
            static fn () => $enrollment->complete(instant('2026-08-15 11:00:00')),
            InvalidEnrollmentState::class,
        );
        $enrollment->submit($snapshot, instant('2026-08-15 10:00:00'));
        $completedAt = instant('2026-08-15 11:00:00');
        $enrollment->complete($completedAt);

        assertSameValue(EnrollmentStatus::Completed, $enrollment->status());
        assertSameValue(true, $enrollment->completedAt() === $completedAt);
        assertSameValue(true, $enrollment->billingInformation() === $billing);
        assertSameValue(true, $enrollment->submissionSnapshot() === $snapshot);
        assertThrows(static fn () => $enrollment->reopen(), InvalidEnrollmentState::class);
        assertThrows(static fn () => $enrollment->cancel(instant('2026-08-15 12:00:00')), InvalidEnrollmentState::class);
    });

    $runner->add('Cancellation is allowed from Draft and Submitted and rejects repetition', function (): void {
        $draft = enrollmentDraft();
        $draftCancelledAt = instant('2026-08-15 10:00:00');
        $draft->cancel($draftCancelledAt);
        assertSameValue(EnrollmentStatus::Cancelled, $draft->status());
        assertSameValue(true, $draft->cancelledAt() === $draftCancelledAt);
        assertSameValue(null, $draft->submissionSnapshot());
        assertThrows(static fn () => $draft->cancel(instant('2026-08-15 11:00:00')), InvalidEnrollmentState::class);

        $submitted = enrollmentDraft();
        $snapshot = newSubmissionSnapshot();
        $submitted->submit($snapshot, instant('2026-08-15 10:00:00'));
        $submitted->cancel(instant('2026-08-15 11:00:00'));
        assertSameValue(EnrollmentStatus::Cancelled, $submitted->status());
        assertSameValue(true, $submitted->submissionSnapshot() === $snapshot);
    });

    $runner->add('Lifecycle rejects timestamps before StartedAt or applicable prior history', function (): void {
        $enrollment = enrollmentDraft(instant('2026-08-15 09:00:00'));
        assertThrows(
            static fn () => $enrollment->submit(newSubmissionSnapshot(), instant('2026-08-15 08:59:59')),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn () => $enrollment->cancel(instant('2026-08-15 08:59:59')),
            InvalidEnrollmentState::class,
        );

        $enrollment->submit(newSubmissionSnapshot(), instant('2026-08-15 10:00:00'));
        assertThrows(
            static fn () => $enrollment->complete(instant('2026-08-15 09:59:59')),
            InvalidEnrollmentState::class,
        );
        assertThrows(
            static fn () => $enrollment->cancel(instant('2026-08-15 09:59:59')),
            InvalidEnrollmentState::class,
        );
        $enrollment->reopen();
        assertThrows(
            static fn () => $enrollment->submit(newSubmissionSnapshot(), instant('2026-08-15 09:59:59')),
            InvalidEnrollmentState::class,
        );
    });

    $runner->add('Lifecycle rejects every transition outside the approved graph', function (): void {
        $draft = enrollmentDraft();
        assertThrows(static fn () => $draft->reopen(), InvalidEnrollmentState::class);
        assertThrows(static fn () => $draft->complete(instant('2026-08-15 10:00:00')), InvalidEnrollmentState::class);

        $submitted = enrollmentDraft();
        $submitted->submit(newSubmissionSnapshot(), instant('2026-08-15 10:00:00'));
        assertThrows(
            static fn () => $submitted->submit(newSubmissionSnapshot(), instant('2026-08-15 11:00:00')),
            InvalidEnrollmentState::class,
        );

        $completed = enrollmentDraft();
        $completed->submit(newSubmissionSnapshot(), instant('2026-08-15 10:00:00'));
        $completed->complete(instant('2026-08-15 11:00:00'));
        assertThrows(static fn () => $completed->reopen(), InvalidEnrollmentState::class);
        assertThrows(
            static fn () => $completed->submit(newSubmissionSnapshot(), instant('2026-08-15 12:00:00')),
            InvalidEnrollmentState::class,
        );
        assertThrows(static fn () => $completed->complete(instant('2026-08-15 12:00:00')), InvalidEnrollmentState::class);
        assertThrows(static fn () => $completed->cancel(instant('2026-08-15 12:00:00')), InvalidEnrollmentState::class);

        $cancelled = enrollmentDraft();
        $cancelled->cancel(instant('2026-08-15 10:00:00'));
        assertThrows(static fn () => $cancelled->reopen(), InvalidEnrollmentState::class);
        assertThrows(
            static fn () => $cancelled->submit(newSubmissionSnapshot(), instant('2026-08-15 11:00:00')),
            InvalidEnrollmentState::class,
        );
        assertThrows(static fn () => $cancelled->complete(instant('2026-08-15 11:00:00')), InvalidEnrollmentState::class);
        assertThrows(static fn () => $cancelled->cancel(instant('2026-08-15 11:00:00')), InvalidEnrollmentState::class);
    });

    $runner->add('Reconstitution accepts every coherent approved persisted lifecycle shape', function (): void {
        $initialDraft = reconstitutedEnrollment(EnrollmentStatus::Draft);
        $reopenedDraft = reconstitutedEnrollment(EnrollmentStatus::Draft, true);
        $submitted = reconstitutedEnrollment(EnrollmentStatus::Submitted, true);
        $completed = reconstitutedEnrollment(EnrollmentStatus::Completed, true);
        $cancelledDraft = reconstitutedEnrollment(EnrollmentStatus::Cancelled, false);
        $cancelledSubmitted = reconstitutedEnrollment(EnrollmentStatus::Cancelled, true);

        assertSameValue(90, $initialDraft->id()?->value());
        assertSameValue(null, $initialDraft->submittedAt());
        assertSameValue(true, $reopenedDraft->submissionSnapshot() !== null);
        assertSameValue(EnrollmentStatus::Submitted, $submitted->status());
        assertSameValue(true, $completed->completedAt() !== null);
        assertSameValue(null, $cancelledDraft->submissionSnapshot());
        assertSameValue(true, $cancelledSubmitted->submissionSnapshot() !== null);
    });

    $runner->add('Reconstitution rejects clearly incoherent persisted lifecycle combinations', function (): void {
        $started = instant('2026-08-15 09:00:00');
        $submitted = instant('2026-08-15 10:00:00');
        $completed = instant('2026-08-15 11:00:00');
        $cancelled = instant('2026-08-15 12:00:00');

        foreach ([
            [EnrollmentStatus::Draft, persistedSubmissionSnapshot(), $submitted, $completed, null],
            [EnrollmentStatus::Submitted, null, $submitted, null, null],
            [EnrollmentStatus::Submitted, persistedSubmissionSnapshot(), null, null, null],
            [EnrollmentStatus::Completed, persistedSubmissionSnapshot(), $submitted, null, null],
            [EnrollmentStatus::Completed, persistedSubmissionSnapshot(), $submitted, $completed, $cancelled],
            [EnrollmentStatus::Cancelled, null, null, null, null],
            [EnrollmentStatus::Cancelled, persistedSubmissionSnapshot(), null, null, $cancelled],
        ] as [$status, $snapshot, $submittedAt, $completedAt, $cancelledAt]) {
            assertThrows(
                static fn () => Enrollment::reconstitute(
                    new EnrollmentId(1),
                    new StudentId(2),
                    new FamilyId(3),
                    new AcademicPeriodId(4),
                    $status,
                    null,
                    null,
                    null,
                    null,
                    false,
                    $snapshot,
                    $started,
                    $submittedAt,
                    $completedAt,
                    $cancelledAt,
                ),
                InvalidEnrollmentState::class,
            );
        }
    });

    $runner->add('Enrollment Domain remains isolated from persistence application delivery and external Aggregates', function (): void {
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
        assertSameValue(27, count($files));
        foreach ([
            'App\\Family\\',
            'App\\Student\\',
            'App\\Representative\\',
            'App\\InstitutionalDocuments\\',
            'PDO',
            'SELECT ',
            'INSERT ',
            'Controller',
            'Request',
            'Response',
            'Session',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        assertSameValue(true, interface_exists(\App\Enrollment\Domain\EnrollmentRepository::class));
        assertSameValue(true, is_dir(__DIR__ . '/../app/Enrollment/Application'));
        assertSameValue(false, is_dir(__DIR__ . '/../app/Enrollment/Delivery'));
    });
}

function enrollmentDraft(?DateTimeImmutable $startedAt = null): Enrollment
{
    return Enrollment::startDraft(
        new StudentId(10),
        new FamilyId(20),
        new AcademicPeriodId(30),
        $startedAt ?? instant('2026-08-15 09:00:00'),
    );
}

function billingInformation(): BillingInformation
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
function medicalArguments(): array
{
    return [false, null, false, null, false, null, false, null, false, null, null, null, null];
}

function medicalInformation(): MedicalInformation
{
    return new MedicalInformation(...medicalArguments());
}

function newAddress(): SubmittedAddressSnapshot
{
    return SubmittedAddressSnapshot::create('Home', 'Main Street', null, null, null, null, null);
}

function persistedAddress(): SubmittedAddressSnapshot
{
    return SubmittedAddressSnapshot::reconstitute(
        new SubmittedAddressSnapshotId(101),
        'Home',
        'Main Street',
        null,
        null,
        null,
        null,
        null,
    );
}

function emergencyContact(int $sortOrder, ?int $priority = null): SubmittedEmergencyContactSnapshot
{
    return SubmittedEmergencyContactSnapshot::create(
        'Contacto ' . $sortOrder,
        'MOTHER',
        'Madre',
        '0990000000',
        null,
        null,
        null,
        $priority,
        $sortOrder,
    );
}

function persistedEmergencyContact(int $id, int $sortOrder): SubmittedEmergencyContactSnapshot
{
    return SubmittedEmergencyContactSnapshot::reconstitute(
        new SubmittedEmergencyContactSnapshotId($id),
        'Contacto ' . $sortOrder,
        'MOTHER',
        'Madre',
        '0990000000',
        null,
        null,
        null,
        null,
        $sortOrder,
    );
}

function authorizedPickup(string $names = 'Persona Autorizada'): SubmittedAuthorizedPickupSnapshot
{
    return SubmittedAuthorizedPickupSnapshot::create(
        $names,
        'UNCLE',
        'Tío',
        '0980000000',
        null,
        'NATIONAL_ID',
        'Cédula',
        '1712345678',
        null,
    );
}

function persistedPickup(int $id): SubmittedAuthorizedPickupSnapshot
{
    return SubmittedAuthorizedPickupSnapshot::reconstitute(
        new SubmittedAuthorizedPickupSnapshotId($id),
        'Persona Autorizada',
        'UNCLE',
        'Tío',
        '0980000000',
        null,
        'NATIONAL_ID',
        'Cédula',
        '1712345678',
        null,
    );
}

/** @param list<SubmittedAuthorizedPickupSnapshot> $pickups */
function newSubmissionSnapshot(array $pickups = []): EnrollmentSubmissionSnapshot
{
    return EnrollmentSubmissionSnapshot::create(
        new RepresentativeId(50),
        instant('2026-08-15 09:30:00'),
        newAddress(),
        [emergencyContact(1)],
        $pickups,
    );
}

function persistedSubmissionSnapshot(): EnrollmentSubmissionSnapshot
{
    return EnrollmentSubmissionSnapshot::reconstitute(
        new EnrollmentSubmissionSnapshotId(100),
        new RepresentativeId(50),
        instant('2026-08-15 09:30:00'),
        persistedAddress(),
        [persistedEmergencyContact(102, 1)],
        [persistedPickup(103)],
    );
}

function reconstitutedEnrollment(EnrollmentStatus $status, bool $hasSubmission = false): Enrollment
{
    $submittedAt = $hasSubmission ? instant('2026-08-15 10:00:00') : null;

    return Enrollment::reconstitute(
        new EnrollmentId(90),
        new StudentId(10),
        new FamilyId(20),
        new AcademicPeriodId(30),
        $status,
        new AcademicPlacement(new GradeId(1), null),
        billingInformation(),
        medicalInformation(),
        new TransportInformation(false),
        false,
        $hasSubmission ? persistedSubmissionSnapshot() : null,
        instant('2026-08-15 09:00:00'),
        $submittedAt,
        $status === EnrollmentStatus::Completed ? instant('2026-08-15 11:00:00') : null,
        $status === EnrollmentStatus::Cancelled ? instant('2026-08-15 12:00:00') : null,
    );
}

function instant(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value . (str_contains($value, '+') ? '' : '+00:00'));
}
