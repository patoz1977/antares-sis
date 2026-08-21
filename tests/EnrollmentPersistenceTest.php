<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\EnrollmentSubmissionSnapshot;
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
use App\Enrollment\Infrastructure\Persistence\PdoEnrollmentRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use ReflectionClass;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentPersistenceTests(TestRunner $runner): void
{
    $runner->add('Enrollment persistence exposes exactly the approved repository contract', function (): void {
        assertSameValue(
            ['findById', 'findByIdForUpdate', 'findByStudentAndAcademicPeriod', 'save'],
            e010PersistencePublicMethods(EnrollmentRepository::class),
        );
        assertSameValue(true, (new ReflectionClass(PdoEnrollmentRepository::class))->implementsInterface(
            EnrollmentRepository::class,
        ));
    });

    $runner->add('Enrollment persistence row lock requires a transaction and loads exact root', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $persisted = $repository->save(e010PersistenceDraft());
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Fixture Enrollment was not persisted.');
        }

        assertThrows(static fn () => $repository->findByIdForUpdate($id), RuntimeException::class);
        $pdo->beginTransaction();
        try {
            assertSameValue(true, $repository->findByIdForUpdate($id)?->id()?->equals($id));
            assertSameValue(null, $repository->findByIdForUpdate(new EnrollmentId(999)));
            assertSameValue(true, $pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    });

    $runner->add('Enrollment persistence inserts Draft with database identity and UTC seconds', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $new = e010PersistenceDraft(new DateTimeImmutable('2026-08-15 04:10:11.987654-05:00'));

        $persisted = $repository->save($new);

        assertSameValue(null, $new->id());
        assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
        assertSameValue('2026-08-15 09:10:11', $persisted->startedAt()->format('Y-m-d H:i:s'));
        assertSameValue('+00:00', $persisted->startedAt()->format('P'));
        $row = $pdo->query('SELECT student_id, family_id, academic_period_id, started_at FROM enrollments')
            ->fetch(PDO::FETCH_ASSOC);
        assertSameValue([1, 1, 1, '2026-08-15 09:10:11'], array_values($row));
    });

    $runner->add('Enrollment persistence roundtrips every complete annual information block', function (): void {
        [$repository] = e010PersistenceFixture();
        $new = Enrollment::startDraft(
            new StudentId(1),
            new FamilyId(1),
            new AcademicPeriodId(1),
            e010PersistenceInstant('2026-08-15 09:00:00'),
            new AcademicPlacement(new GradeId(1), new SectionId(1)),
            e010PersistenceBilling(),
            e010PersistenceMedical(),
            new TransportInformation(true),
            true,
        );

        $persisted = $repository->save($new);
        $found = $repository->findByStudentAndAcademicPeriod(new StudentId(1), new AcademicPeriodId(1));

        assertSameValue(true, $found !== null && $found->id()?->equals($persisted->id()));
        assertSameValue(1, $found->academicPlacement()?->gradeId()->value());
        assertSameValue(1, $found->academicPlacement()?->sectionId()?->value());
        assertSameValue('Familia Ñandú', $found->billingInformation()?->legalName());
        assertSameValue(true, $found->medicalInformation()?->hasMedicalCondition());
        assertSameValue('Condición ñ', $found->medicalInformation()?->medicalConditionDetail());
        assertSameValue(true, $found->transportInformation()?->requiresInstitutionalTransport());
        assertSameValue(true, $found->isAuthorizedToLeaveAlone());
    });

    $runner->add('Enrollment physical uniqueness and absent lookups remain exact', function (): void {
        [$repository] = e010PersistenceFixture();
        $repository->save(e010PersistenceDraft());

        assertThrows(static fn () => $repository->save(e010PersistenceDraft()), PDOException::class);
        assertSameValue(null, $repository->findById(new EnrollmentId(999)));
        assertSameValue(null, $repository->findByStudentAndAcademicPeriod(
            new StudentId(2),
            new AcademicPeriodId(1),
        ));
    });

    $runner->add('Enrollment update changes only approved mutable annual values', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $persisted = $repository->save(e010PersistenceDraft());
        $persisted->updateAcademicPlacement(new AcademicPlacement(new GradeId(1), null));
        $persisted->updateBillingInformation(e010PersistenceBilling());
        $persisted->updateMedicalInformation(e010PersistenceMedical());
        $persisted->updateTransportInformation(new TransportInformation(false));
        $persisted->updateLeaveAloneAuthorization(true);

        $updated = $repository->save($persisted);

        assertSameValue(1, $updated->studentId()->value());
        assertSameValue(1, $updated->familyId()->value());
        assertSameValue(1, $updated->academicPeriodId()->value());
        assertSameValue('2026-08-15 09:00:00', $updated->startedAt()->format('Y-m-d H:i:s'));
        assertSameValue(true, $updated->isAuthorizedToLeaveAlone());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
    });

    $runner->add('Enrollment update fails closed for changed ownership started_at or missing row', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $persisted = $repository->save(e010PersistenceDraft());
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Fixture Enrollment was not persisted.');
        }

        foreach ([
            "UPDATE enrollments SET student_id = 2 WHERE id = {$id->value()}",
            "UPDATE enrollments SET family_id = 2 WHERE id = {$id->value()}",
            "UPDATE enrollments SET academic_period_id = 2 WHERE id = {$id->value()}",
            "UPDATE enrollments SET started_at = '2026-08-15 09:00:01' WHERE id = {$id->value()}",
        ] as $sql) {
            [$caseRepository, $casePdo] = e010PersistenceFixture();
            $casePersisted = $caseRepository->save(e010PersistenceDraft());
            $casePdo->exec(str_replace((string) $id->value(), (string) $casePersisted->id()?->value(), $sql));
            assertThrows(static fn () => $caseRepository->save($casePersisted), RuntimeException::class);
        }

        $pdo->exec('DELETE FROM enrollments WHERE id = ' . $id->value());
        assertThrows(static fn () => $repository->save($persisted), RuntimeException::class);
    });

    $runner->add('Enrollment status mapping requires one exact supported ENROLLMENT_STATUS row', function (): void {
        foreach ([
            "DELETE FROM statuses WHERE code = 'DRAFT' AND status_type_id = 2",
            "INSERT INTO statuses (id, status_type_id, code) VALUES (24, 2, 'DRAFT')",
        ] as $corruption) {
            [$repository, $pdo] = e010PersistenceFixture();
            $pdo->exec($corruption);
            assertThrows(static fn () => $repository->save(e010PersistenceDraft()), RuntimeException::class);
        }

        [$repository, $pdo] = e010PersistenceFixture();
        $persisted = $repository->save(e010PersistenceDraft());
        $pdo->exec('UPDATE enrollments SET status_id = 10 WHERE id = ' . $persisted->id()?->value());
        assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);
        $pdo->exec('UPDATE enrollments SET status_id = 14 WHERE id = ' . $persisted->id()?->value());
        assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);
    });

    $runner->add('Enrollment reconstruction rejects every partial optional block and invalid scalar', function (): void {
        $corruptions = [
            'UPDATE enrollments SET section_id = 1 WHERE id = 1',
            "UPDATE enrollments SET billing_identification_number = '1' WHERE id = 1",
            'UPDATE enrollments SET has_medical_condition = 0 WHERE id = 1',
            "UPDATE enrollments SET medical_condition_detail = 'orphan' WHERE id = 1",
            'UPDATE enrollments SET is_authorized_to_leave_alone = 2 WHERE id = 1',
            "UPDATE enrollments SET started_at = 'invalid' WHERE id = 1",
        ];

        foreach ($corruptions as $corruption) {
            [$repository, $pdo] = e010PersistenceFixture(false);
            $repository->save(e010PersistenceDraft());
            $pdo->exec($corruption);
            assertThrows(static fn () => $repository->findById(new EnrollmentId(1)), RuntimeException::class);
        }
    });

    $runner->add('Submitted Enrollment persists complete snapshot identities and deterministic children', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00.654321'));

        $persisted = $repository->save($new);
        $snapshot = $persisted->submissionSnapshot();

        assertSameValue(EnrollmentStatus::Submitted, $persisted->status());
        assertSameValue(true, ($snapshot?->id()?->value() ?? 0) > 0);
        assertSameValue(true, ($snapshot?->address()->id()?->value() ?? 0) > 0);
        assertSameValue([1, 2], array_map(
            static fn (SubmittedEmergencyContactSnapshot $contact): int => $contact->sortOrder(),
            $snapshot?->emergencyContacts() ?? [],
        ));
        assertSameValue(2, count($snapshot?->authorizedPickups() ?? []));
        assertSameValue('2026-08-15 10:00:00', $persisted->submittedAt()?->format('Y-m-d H:i:s'));
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_addresses')->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_emergency_contacts')->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_authorized_pickups')->fetchColumn());
    });

    $runner->add('Persisted snapshot structure corruption fails closed during reconstruction', function (): void {
        foreach ([
            'DELETE FROM snapshot_addresses',
            'DELETE FROM snapshot_emergency_contacts',
            'UPDATE snapshot_addresses SET longitude = NULL',
        ] as $corruption) {
            [$repository, $pdo] = e010PersistenceFixture(false);
            $enrollment = e010PersistenceDraft();
            $enrollment->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
            $persisted = $repository->save($enrollment);
            $pdo->exec($corruption);
            assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);
        }

        [$repository, $pdo] = e010PersistenceFixture(false);
        $enrollment = e010PersistenceDraft();
        $enrollment->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $persisted = $repository->save($enrollment);
        $pdo->exec(
            'INSERT INTO enrollment_submission_snapshots '
            . "(enrollment_id, created_by_representative_id, created_at) VALUES (1, 1, '2026-08-15 10:00:00')"
        );
        assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);
    });

    $runner->add('Persisted submission snapshot remains immutable and cannot be removed', function (): void {
        [$repository] = e010PersistenceFixture();
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $persisted = $repository->save($new);

        assertSameValue(true, $repository->save($persisted)->submissionSnapshot()?->id()?->equals(
            $persisted->submissionSnapshot()?->id(),
        ));

        $withoutSnapshot = Enrollment::reconstitute(
            $persisted->id(),
            $persisted->studentId(),
            $persisted->familyId(),
            $persisted->academicPeriodId(),
            EnrollmentStatus::Draft,
            $persisted->academicPlacement(),
            $persisted->billingInformation(),
            $persisted->medicalInformation(),
            $persisted->transportInformation(),
            $persisted->isAuthorizedToLeaveAlone(),
            null,
            $persisted->startedAt(),
            null,
            null,
            null,
        );
        assertThrows(static fn () => $repository->save($withoutSnapshot), RuntimeException::class);

        $changed = e010PersistenceReconstituteWithSnapshot(
            $persisted,
            e010PersistencePersistedSnapshotFrom($persisted->submissionSnapshot(), 'Changed address'),
        );
        assertThrows(static fn () => $repository->save($changed), RuntimeException::class);
    });

    $runner->add('Reopen and resubmit atomically replace snapshot root and cascade old children', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $persisted = $repository->save($new);
        $oldSnapshotId = $persisted->submissionSnapshot()?->id()?->value();
        $oldAddressId = $persisted->submissionSnapshot()?->address()->id()?->value();
        $persisted->reopen();
        $persisted->submit(
            e010PersistenceNewSnapshot('Replacement'),
            e010PersistenceInstant('2026-08-15 11:00:00'),
        );

        $replaced = $repository->save($persisted);

        assertSameValue(false, $replaced->submissionSnapshot()?->id()?->value() === $oldSnapshotId);
        assertSameValue(false, $replaced->submissionSnapshot()?->address()->id()?->value() === $oldAddressId);
        assertSameValue(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM enrollment_submission_snapshots WHERE id = ' . $oldSnapshotId
        )->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM enrollment_submission_snapshots')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_addresses')->fetchColumn());
    });

    $runner->add('Persisted pickup comparison is semantic and independent of caller order', function (): void {
        [$repository] = e010PersistenceFixture();
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $persisted = $repository->save($new);
        $snapshot = $persisted->submissionSnapshot();
        if ($snapshot === null) {
            throw new RuntimeException('Fixture snapshot was not persisted.');
        }

        $reordered = EnrollmentSubmissionSnapshot::reconstitute(
            $snapshot->id(),
            $snapshot->createdByRepresentativeId(),
            $snapshot->createdAt(),
            $snapshot->address(),
            $snapshot->emergencyContacts(),
            array_reverse($snapshot->authorizedPickups()),
        );
        $requested = e010PersistenceReconstituteWithSnapshot($persisted, $reordered);

        assertSameValue(true, $repository->save($requested)->submissionSnapshot()?->id()?->equals($snapshot->id()));
    });

    $runner->add('Enrollment lifecycle statuses and timestamps persist through completion and cancellation', function (): void {
        [$repository] = e010PersistenceFixture();
        $completed = e010PersistenceDraft();
        $completed->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $completed = $repository->save($completed);
        $completed->complete(e010PersistenceInstant('2026-08-15 11:00:00'));
        $completed = $repository->save($completed);
        assertSameValue(EnrollmentStatus::Completed, $completed->status());
        assertSameValue('2026-08-15 11:00:00', $completed->completedAt()?->format('Y-m-d H:i:s'));

        [$repository] = e010PersistenceFixture();
        $cancelled = e010PersistenceDraft();
        $cancelled->cancel(e010PersistenceInstant('2026-08-15 12:00:00'));
        $cancelled = $repository->save($cancelled);
        assertSameValue(EnrollmentStatus::Cancelled, $cancelled->status());
        assertSameValue('2026-08-15 12:00:00', $cancelled->cancelledAt()?->format('Y-m-d H:i:s'));
    });

    $runner->add('Owned Enrollment transaction rolls back a failed new snapshot child', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $pdo->exec(
            "CREATE TRIGGER reject_snapshot_address BEFORE INSERT ON snapshot_addresses "
            . "BEGIN SELECT RAISE(ABORT, 'rejected address'); END"
        );
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));

        assertThrows(static fn () => $repository->save($new), PDOException::class);
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM enrollment_submission_snapshots')->fetchColumn());
    });

    $runner->add('Failed snapshot replacement restores prior root and every prior child', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $new = e010PersistenceDraft();
        $new->submit(e010PersistenceNewSnapshot('Original'), e010PersistenceInstant('2026-08-15 10:00:00'));
        $persisted = $repository->save($new);
        $oldSnapshotId = $persisted->submissionSnapshot()?->id()?->value();
        $oldAddressId = $persisted->submissionSnapshot()?->address()->id()?->value();
        $persisted->reopen();
        $persisted->submit(e010PersistenceNewSnapshot('Rejected'), e010PersistenceInstant('2026-08-15 11:00:00'));
        $pdo->exec(
            "CREATE TRIGGER reject_replacement BEFORE INSERT ON snapshot_addresses "
            . "WHEN NEW.label = 'Rejected' BEGIN SELECT RAISE(ABORT, 'rejected replacement'); END"
        );

        assertThrows(static fn () => $repository->save($persisted), PDOException::class);
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM enrollment_submission_snapshots WHERE id = ' . $oldSnapshotId
        )->fetchColumn());
        assertSameValue(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM snapshot_addresses WHERE id = ' . $oldAddressId
        )->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_emergency_contacts')->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM snapshot_authorized_pickups')->fetchColumn());
        assertSameValue('SUBMITTED', $repository->findById($persisted->id())?->status()->value);
        assertSameValue('Original', $repository->findById($persisted->id())?->submissionSnapshot()?->address()->label());
    });

    $runner->add('Enrollment save participates in caller transaction without owning its outcome', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $pdo->beginTransaction();
        $persisted = $repository->save(e010PersistenceDraft());
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());

        $pdo->beginTransaction();
        $failed = e010PersistenceDraft();
        $failed->submit(e010PersistenceNewSnapshot(), e010PersistenceInstant('2026-08-15 10:00:00'));
        $pdo->exec(
            "CREATE TRIGGER reject_external_snapshot BEFORE INSERT ON snapshot_addresses "
            . "BEGIN SELECT RAISE(ABORT, 'external failure'); END"
        );
        assertThrows(static fn () => $repository->save($failed), PDOException::class);
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM enrollment_submission_snapshots')->fetchColumn());
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
    });

    $runner->add('Enrollment persistence stays prepared isolated and schema preserving', function (): void {
        $source = (string) file_get_contents(
            __DIR__ . '/../app/Enrollment/Infrastructure/Persistence/PdoEnrollmentRepository.php',
        );
        $domainSource = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../app/Enrollment/Domain',
        )) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $domainSource .= (string) file_get_contents($file->getPathname());
            }
        }

        assertSameValue(true, str_contains($source, '->prepare('));
        foreach (['MAX(', 'last_insert_rowid', 'migration', 'App\\Student\\Domain\\Student',
            'App\\Family\\Domain\\Family', 'Controller', 'Request', 'Response', 'Session'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
            assertSameValue(false, str_contains($domainSource, $forbidden));
        }
    });
}

/** @return list<string> */
function e010PersistencePublicMethods(string $class): array
{
    $methods = array_map(
        static fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
    );
    sort($methods, SORT_STRING);

    return $methods;
}

/** @return array{PdoEnrollmentRepository, PDO} */
function e010PersistenceFixture(bool $constraints = true): array
{
    $manager = familySqliteManager();
    $pdo = $manager->connection();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $uniqueEnrollment = $constraints ? ', UNIQUE (student_id, academic_period_id)' : '';
    $uniqueSnapshot = $constraints ? ', UNIQUE (enrollment_id)' : '';
    $uniqueAddress = $constraints ? ', UNIQUE (enrollment_submission_snapshot_id)' : '';
    $uniqueEmergency = $constraints ? ', UNIQUE (enrollment_submission_snapshot_id, sort_order)' : '';
    $rootForeignKeys = $constraints
        ? ', FOREIGN KEY (student_id) REFERENCES students(id)'
            . ', FOREIGN KEY (family_id) REFERENCES families(id)'
            . ', FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id)'
            . ', FOREIGN KEY (status_id) REFERENCES statuses(id)'
            . ', FOREIGN KEY (grade_id) REFERENCES grades(id)'
            . ', FOREIGN KEY (section_id) REFERENCES sections(id)'
            . ', FOREIGN KEY (billing_identification_type_id) REFERENCES document_types(id)'
        : '';
    $snapshotForeignKeys = $constraints
        ? ', FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE RESTRICT'
            . ', FOREIGN KEY (created_by_representative_id) REFERENCES representatives(id)'
        : '';
    $childForeignKey = $constraints
        ? ', FOREIGN KEY (enrollment_submission_snapshot_id) '
            . 'REFERENCES enrollment_submission_snapshots(id) ON DELETE CASCADE'
        : '';

    $pdo->exec(
        'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
        . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
        . 'CREATE TABLE students (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE families (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE academic_periods (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE grades (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE sections (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE document_types (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE representatives (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE enrollments ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER NOT NULL, family_id INTEGER NOT NULL, '
        . 'academic_period_id INTEGER NOT NULL, status_id INTEGER NOT NULL, grade_id INTEGER NULL, '
        . 'section_id INTEGER NULL, billing_identification_type_id INTEGER NULL, '
        . 'billing_identification_number TEXT NULL, billing_legal_name TEXT NULL, billing_address TEXT NULL, '
        . 'billing_email TEXT NULL, billing_phone TEXT NULL, has_medical_condition INTEGER NULL, '
        . 'medical_condition_detail TEXT NULL, has_allergies INTEGER NULL, allergy_detail TEXT NULL, '
        . 'takes_permanent_medication INTEGER NULL, medication_name TEXT NULL, '
        . 'requires_special_care INTEGER NULL, special_care_detail TEXT NULL, '
        . 'has_medical_insurance INTEGER NULL, insurance_provider TEXT NULL, pediatrician_name TEXT NULL, '
        . 'pediatrician_phone TEXT NULL, medical_observations TEXT NULL, '
        . 'requires_institutional_transport INTEGER NULL, is_authorized_to_leave_alone INTEGER NOT NULL, '
        . 'started_at TEXT NOT NULL, submitted_at TEXT NULL, completed_at TEXT NULL, cancelled_at TEXT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
        . $uniqueEnrollment . $rootForeignKeys . ');'
        . 'CREATE TABLE enrollment_submission_snapshots ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_id INTEGER NOT NULL, '
        . 'created_by_representative_id INTEGER NOT NULL, created_at TEXT NOT NULL'
        . $uniqueSnapshot . $snapshotForeignKeys . ');'
        . 'CREATE TABLE snapshot_addresses ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_submission_snapshot_id INTEGER NOT NULL, '
        . 'label TEXT NOT NULL, main_street TEXT NOT NULL, street_number TEXT NULL, secondary_street TEXT NULL, '
        . 'sector TEXT NULL, reference TEXT NULL, latitude NUMERIC NULL, longitude NUMERIC NULL'
        . $uniqueAddress . $childForeignKey . ');'
        . 'CREATE TABLE snapshot_emergency_contacts ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_submission_snapshot_id INTEGER NOT NULL, '
        . 'names TEXT NOT NULL, relationship_type_code TEXT NOT NULL, relationship_type_name TEXT NOT NULL, '
        . 'mobile_phone TEXT NOT NULL, phone TEXT NULL, email TEXT NULL, observations TEXT NULL, '
        . 'priority INTEGER NULL, sort_order INTEGER NOT NULL'
        . $uniqueEmergency . $childForeignKey . ');'
        . 'CREATE TABLE snapshot_authorized_pickups ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, enrollment_submission_snapshot_id INTEGER NOT NULL, '
        . 'names TEXT NOT NULL, relationship_type_code TEXT NOT NULL, relationship_type_name TEXT NOT NULL, '
        . 'mobile_phone TEXT NOT NULL, phone TEXT NULL, document_type_code TEXT NOT NULL, '
        . 'document_type_name TEXT NOT NULL, document_number TEXT NOT NULL, observations TEXT NULL'
        . $childForeignKey . ');'
        . "INSERT INTO status_types VALUES (1, 'GENERAL_STATUS'), (2, 'ENROLLMENT_STATUS');"
        . "INSERT INTO statuses VALUES "
        . "(10, 1, 'ACTIVE'), (11, 1, 'INACTIVE'), (14, 2, 'BROKEN'), "
        . "(20, 2, 'DRAFT'), (21, 2, 'SUBMITTED'), (22, 2, 'COMPLETED'), (23, 2, 'CANCELLED');"
        . 'INSERT INTO students VALUES (1), (2);'
        . 'INSERT INTO families VALUES (1), (2);'
        . 'INSERT INTO academic_periods VALUES (1), (2);'
        . 'INSERT INTO grades VALUES (1), (2);'
        . 'INSERT INTO sections VALUES (1), (2);'
        . 'INSERT INTO document_types VALUES (1), (2);'
        . 'INSERT INTO representatives VALUES (1), (2);'
    );

    return [new PdoEnrollmentRepository($manager), $pdo];
}

function e010PersistenceDraft(?DateTimeImmutable $startedAt = null): Enrollment
{
    return Enrollment::startDraft(
        new StudentId(1),
        new FamilyId(1),
        new AcademicPeriodId(1),
        $startedAt ?? e010PersistenceInstant('2026-08-15 09:00:00'),
    );
}

function e010PersistenceBilling(): BillingInformation
{
    return new BillingInformation(
        new IdentificationTypeId(1),
        '0912345678',
        'Familia Ñandú',
        'Av. Principal 123',
        'familia@example.test',
        '+593 99 000 0000',
    );
}

function e010PersistenceMedical(): MedicalInformation
{
    return new MedicalInformation(
        true,
        'Condición ñ',
        false,
        null,
        true,
        'Medicamento',
        false,
        null,
        true,
        'Seguro',
        'Pediatra',
        '02 000 0000',
        'Observación',
    );
}

function e010PersistenceNewSnapshot(string $addressLabel = 'Home'): EnrollmentSubmissionSnapshot
{
    return EnrollmentSubmissionSnapshot::create(
        new RepresentativeId(1),
        e010PersistenceInstant('2026-08-15 09:30:00.987654'),
        SubmittedAddressSnapshot::create(
            $addressLabel,
            'Calle Principal',
            'N1-23',
            'Calle Secundaria',
            'Sector Ñ',
            'Casa azul',
            new Geolocation('-0.1234567', '-78.1234567'),
        ),
        [
            e010PersistenceEmergency(2, null),
            e010PersistenceEmergency(1, 1),
        ],
        [
            e010PersistencePickup('Persona B', 'B-2'),
            e010PersistencePickup('Persona A', 'A-1'),
        ],
    );
}

function e010PersistenceEmergency(int $sortOrder, ?int $priority): SubmittedEmergencyContactSnapshot
{
    return SubmittedEmergencyContactSnapshot::create(
        'Contacto ' . $sortOrder,
        'MOTHER',
        'Madre',
        '099000000' . $sortOrder,
        null,
        'contact' . $sortOrder . '@example.test',
        'Observación ' . $sortOrder,
        $priority,
        $sortOrder,
    );
}

function e010PersistencePickup(string $names, string $document): SubmittedAuthorizedPickupSnapshot
{
    return SubmittedAuthorizedPickupSnapshot::create(
        $names,
        'UNCLE',
        'Tío',
        '0980000000',
        null,
        'NATIONAL_ID',
        'Cédula',
        $document,
        'Observación pickup',
    );
}

function e010PersistencePersistedSnapshotFrom(
    ?EnrollmentSubmissionSnapshot $source,
    string $addressLabel,
): EnrollmentSubmissionSnapshot {
    if ($source === null || $source->id() === null || $source->address()->id() === null) {
        throw new RuntimeException('A persisted source snapshot is required.');
    }

    $address = $source->address();

    return EnrollmentSubmissionSnapshot::reconstitute(
        $source->id(),
        $source->createdByRepresentativeId(),
        $source->createdAt(),
        SubmittedAddressSnapshot::reconstitute(
            $address->id(),
            $addressLabel,
            $address->mainStreet(),
            $address->streetNumber(),
            $address->secondaryStreet(),
            $address->sector(),
            $address->reference(),
            $address->geolocation(),
        ),
        $source->emergencyContacts(),
        $source->authorizedPickups(),
    );
}

function e010PersistenceReconstituteWithSnapshot(
    Enrollment $source,
    EnrollmentSubmissionSnapshot $snapshot,
): Enrollment {
    $id = $source->id();
    if ($id === null) {
        throw new RuntimeException('A persisted source Enrollment is required.');
    }

    return Enrollment::reconstitute(
        $id,
        $source->studentId(),
        $source->familyId(),
        $source->academicPeriodId(),
        $source->status(),
        $source->academicPlacement(),
        $source->billingInformation(),
        $source->medicalInformation(),
        $source->transportInformation(),
        $source->isAuthorizedToLeaveAlone(),
        $snapshot,
        $source->startedAt(),
        $source->submittedAt(),
        $source->completedAt(),
        $source->cancelledAt(),
    );
}

function e010PersistenceInstant(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value . (str_contains($value, '+') || str_contains($value, '-05:00') ? '' : '+00:00'));
}
