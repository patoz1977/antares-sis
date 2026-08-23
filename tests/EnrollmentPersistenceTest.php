<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
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
        $id = $persisted->id() ?? throw new RuntimeException('Fixture Enrollment was not persisted.');

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
        assertSameValue(1, $found->academicPlacement()?->sectionId()?->value());
        assertSameValue('Familia Ñandú', $found->billingInformation()?->legalName());
        assertSameValue('Condición ñ', $found->medicalInformation()?->medicalConditionDetail());
        assertSameValue(true, $found->transportInformation()?->requiresInstitutionalTransport());
        assertSameValue(true, $found->isAuthorizedToLeaveAlone());
    });

    $runner->add('Enrollment physical uniqueness and absent lookups remain exact', function (): void {
        [$repository] = e010PersistenceFixture();
        $repository->save(e010PersistenceDraft());

        assertThrows(static fn () => $repository->save(e010PersistenceDraft()), PDOException::class);
        assertSameValue(null, $repository->findById(new EnrollmentId(999)));
        assertSameValue(null, $repository->findByStudentAndAcademicPeriod(new StudentId(2), new AcademicPeriodId(1)));
    });

    $runner->add('Enrollment update preserves ownership and rejects a disappearing row', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $persisted = $repository->save(e010PersistenceDraft());
        $persisted->updateAcademicPlacement(new AcademicPlacement(new GradeId(1), null));
        $persisted->updateBillingInformation(e010PersistenceBilling());
        $persisted->updateMedicalInformation(e010PersistenceMedical());
        $persisted->updateTransportInformation(new TransportInformation(false));
        $persisted->updateLeaveAloneAuthorization(true);

        $updated = $repository->save($persisted);
        assertSameValue([1, 1, 1], [
            $updated->studentId()->value(), $updated->familyId()->value(), $updated->academicPeriodId()->value(),
        ]);
        assertSameValue(true, $updated->isAuthorizedToLeaveAlone());

        $pdo->exec('DELETE FROM enrollments WHERE id = ' . $updated->id()?->value());
        assertThrows(static fn () => $repository->save($updated), RuntimeException::class);
    });

    $runner->add('Enrollment reconstruction rejects status corruption and partial optional blocks', function (): void {
        foreach ([
            'UPDATE enrollments SET status_id = 10 WHERE id = 1',
            'UPDATE enrollments SET status_id = 14 WHERE id = 1',
            'UPDATE enrollments SET section_id = 1 WHERE id = 1',
            "UPDATE enrollments SET billing_identification_number = '1' WHERE id = 1",
            'UPDATE enrollments SET has_medical_condition = 0 WHERE id = 1',
            'UPDATE enrollments SET is_authorized_to_leave_alone = 2 WHERE id = 1',
        ] as $corruption) {
            [$repository, $pdo] = e010PersistenceFixture(false);
            $repository->save(e010PersistenceDraft());
            $pdo->exec($corruption);
            assertThrows(static fn () => $repository->findById(new EnrollmentId(1)), RuntimeException::class);
        }
    });

    $runner->add('Enrollment lifecycle persists without snapshot tables', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $submitted = e010PersistenceDraft();
        $submitted->submit(e010PersistenceInstant('2026-08-15 10:00:00'));
        $submitted = $repository->save($submitted);
        assertSameValue(EnrollmentStatus::Submitted, $submitted->status());
        assertSameValue('2026-08-15 10:00:00', $submitted->submittedAt()?->format('Y-m-d H:i:s'));

        $submitted->complete(e010PersistenceInstant('2026-08-15 11:00:00'));
        $completed = $repository->save($submitted);
        assertSameValue(EnrollmentStatus::Completed, $completed->status());
        assertSameValue('2026-08-15 11:00:00', $completed->completedAt()?->format('Y-m-d H:i:s'));

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach (['enrollment_submission_snapshots', 'snapshot_addresses', 'snapshot_emergency_contacts', 'snapshot_authorized_pickups'] as $removed) {
            assertSameValue(false, in_array($removed, $tables, true));
        }
    });

    $runner->add('Enrollment save participates in caller transaction without owning it', function (): void {
        [$repository, $pdo] = e010PersistenceFixture();
        $pdo->beginTransaction();
        $persisted = $repository->save(e010PersistenceDraft());
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());
    });

    $runner->add('Enrollment persistence stays prepared isolated and snapshot free', function (): void {
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
        foreach (['SubmissionSnapshot', 'snapshot_', 'MAX(', 'last_insert_rowid', 'migration',
            'App\\Student\\Domain\\Student', 'App\\Family\\Domain\\Family', 'Controller', 'Request', 'Response', 'Session'] as $forbidden) {
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
    $rootForeignKeys = $constraints
        ? ', FOREIGN KEY (student_id) REFERENCES students(id)'
            . ', FOREIGN KEY (family_id) REFERENCES families(id)'
            . ', FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id)'
            . ', FOREIGN KEY (status_id) REFERENCES statuses(id)'
            . ', FOREIGN KEY (grade_id) REFERENCES grades(id)'
            . ', FOREIGN KEY (section_id) REFERENCES sections(id)'
            . ', FOREIGN KEY (billing_identification_type_id) REFERENCES document_types(id)'
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
    );

    return [new PdoEnrollmentRepository($manager), $pdo];
}

function e010PersistenceDraft(?DateTimeImmutable $startedAt = null): Enrollment
{
    return Enrollment::startDraft(
        new StudentId(1), new FamilyId(1), new AcademicPeriodId(1),
        $startedAt ?? e010PersistenceInstant('2026-08-15 09:00:00'),
    );
}

function e010PersistenceBilling(): BillingInformation
{
    return new BillingInformation(
        new IdentificationTypeId(1), '0912345678', 'Familia Ñandú', 'Av. Principal 123',
        'familia@example.test', '+593 99 000 0000',
    );
}

function e010PersistenceMedical(): MedicalInformation
{
    return new MedicalInformation(
        true, 'Condición ñ', false, null, true, 'Medicamento', false, null, true, 'Seguro',
        'Pediatra', '02 000 0000', 'Observación',
    );
}

function e010PersistenceInstant(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value . (str_contains($value, '+') || str_contains($value, '-05:00') ? '' : '+00:00'));
}
