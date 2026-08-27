<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Infrastructure\Reporting\PdoAcademicPeriodReportingQuery;
use App\Enrollment\Infrastructure\Reporting\PdoEnrollmentSummaryQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentBillingReportQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentEnrollmentListQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentMedicalReportQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentRepresentativeDirectoryQuery;
use PDO;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentReportingPersistenceTests(TestRunner $runner): void
{
    $runner->add('E013 Phase 2 PDO periods and summary map selected-period rows including inactive Student and null placement', function (): void {
        [$manager] = enrollmentReportingFixture();
        $periods = (new PdoAcademicPeriodReportingQuery($manager))->findAll();
        assertSameValue([[100, 'INACTIVE'], [101, 'ACTIVE']], array_map(
            static fn ($period): array => [$period->id, $period->status->value],
            $periods,
        ));
        $summary = (new PdoEnrollmentSummaryQuery($manager))->fetch(101);
        assertSameValue(5, array_sum(array_map(static fn ($row): int => $row->count, $summary)));
        assertSameValue(false, in_array(EnrollmentReportingStatus::NotStarted, array_column($summary, 'status'), true));
        assertSameValue(true, count(array_filter($summary, static fn ($row): bool => $row->gradeId === null)) === 2);
        assertSameValue([], (new PdoEnrollmentSummaryQuery($manager))->fetch(999));
    });

    $runner->add('E013 Phase 2 PDO Student list includes all and only ACTIVE Students with exact statuses and deterministic order', function (): void {
        [$manager] = enrollmentReportingFixture();
        $rows = (new PdoStudentEnrollmentListQuery($manager))->fetch(101);
        assertSameValue([2, 3, 5, 1, 4], array_column($rows, 'studentId'));
        assertSameValue(
            ['DRAFT', 'SUBMITTED', 'CANCELLED', 'NOT STARTED', 'COMPLETED'],
            array_map(static fn ($row): string => $row->status->value, $rows),
        );
        assertSameValue(false, property_exists($rows[0], 'familyId'));
    });

    $runner->add('E013 Phase 2 PDO directory combines historical annual data with current Family Representative contacts and Address', function (): void {
        [$manager] = enrollmentReportingFixture();
        $current = (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(101);
        $row = array_values(array_filter($current, static fn ($candidate): bool => $candidate->studentId === 2))[0];
        assertSameValue(['Grade One', 'A', 'DRAFT'], [$row->gradeName, $row->sectionName, $row->status->value]);
        assertSameValue(['Representative', 'Current', '0990000000', 'work@example.test'], [
            $row->representativeSurnames, $row->representativeNames, $row->representativeMobilePhone,
            $row->representativeWorkEmail,
        ]);
        assertSameValue('Current Street 10, Cross Street, Current Sector, Current reference', $row->studentAddress);

        $historical = (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(100);
        $historicalRow = array_values(array_filter($historical, static fn ($candidate): bool => $candidate->studentId === 2))[0];
        assertSameValue(['Grade Two', null, 'COMPLETED'], [
            $historicalRow->gradeName, $historicalRow->sectionName, $historicalRow->status->value,
        ]);
        assertSameValue('0990000000', $historicalRow->representativeMobilePhone);
        assertSameValue('Current Street 10, Cross Street, Current Sector, Current reference', $historicalRow->studentAddress);
    });

    $runner->add('E013 Phase 2 PDO directory preserves valid absences and fails closed on current multiplicity', function (): void {
        [$manager, $pdo] = enrollmentReportingFixture();
        $rows = (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(101);
        $withoutFamily = array_values(array_filter($rows, static fn ($row): bool => $row->studentId === 1))[0];
        assertSameValue([null, null, null], [
            $withoutFamily->representativeNames, $withoutFamily->studentAddress, $withoutFamily->gradeId,
        ]);
        $pdo->exec("INSERT INTO family_representatives VALUES (2, 500, 700, '2026-01-02 00:00:00', NULL, 1, 1)");
        reportingAssertThrows(
            static fn (): mixed => (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(101),
            RuntimeException::class,
        );
    });

    $runner->add('E013 Phase 2 PDO directory fails closed on multiple current Student Address assignments', function (): void {
        [$manager, $pdo] = enrollmentReportingFixture();
        $pdo->exec("INSERT INTO family_addresses VALUES (901, 500, 'Other Street', NULL, NULL, NULL, NULL, 1)");
        $pdo->exec("INSERT INTO student_address_assignments VALUES (2, 500, 2, 901, '2026-01-02 00:00:00', NULL)");
        reportingAssertThrows(
            static fn (): mixed => (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(101),
            RuntimeException::class,
        );
    });

    $runner->add('E013 Phase 2 PDO Billing maps complete selected-period annual data and valid blanks', function (): void {
        [$manager] = enrollmentReportingFixture();
        $current = (new PdoStudentBillingReportQuery($manager))->fetch(101);
        $complete = array_values(array_filter($current, static fn ($row): bool => $row->studentId === 3))[0];
        assertSameValue(['National ID', 'BILL-3', 'Billing Three', 'billing@example.test'], [
            $complete->identificationType, $complete->identificationNumber, $complete->legalName, $complete->billingEmail,
        ]);
        $blank = array_values(array_filter($current, static fn ($row): bool => $row->studentId === 1))[0];
        assertSameValue(['NOT STARTED', null], [$blank->status->value, $blank->legalName]);
        $historical = (new PdoStudentBillingReportQuery($manager))->fetch(100);
        $historicalRow = array_values(array_filter($historical, static fn ($row): bool => $row->studentId === 2))[0];
        assertSameValue('Historical Billing', $historicalRow->legalName);
        assertSameValue(false, property_exists($complete, 'balance'));
    });

    $runner->add('E013 Phase 2 PDO Medical maps all approved selected-period fields and valid blanks', function (): void {
        [$manager] = enrollmentReportingFixture();
        $current = (new PdoStudentMedicalReportQuery($manager))->fetch(101);
        $complete = array_values(array_filter($current, static fn ($row): bool => $row->studentId === 3))[0];
        assertSameValue([true, 'Condition', true, 'Allergy', true, 'Medication', true, 'Care', true, 'Insurance'], [
            $complete->hasMedicalCondition, $complete->medicalConditionDetail,
            $complete->hasAllergies, $complete->allergyDetail,
            $complete->takesPermanentMedication, $complete->medicationName,
            $complete->requiresSpecialCare, $complete->specialCareDetail,
            $complete->hasMedicalInsurance, $complete->insuranceProvider,
        ]);
        $blank = array_values(array_filter($current, static fn ($row): bool => $row->studentId === 1))[0];
        assertSameValue(['NOT STARTED', null], [$blank->status->value, $blank->hasMedicalCondition]);
        $historical = (new PdoStudentMedicalReportQuery($manager))->fetch(100);
        $historicalRow = array_values(array_filter($historical, static fn ($row): bool => $row->studentId === 2))[0];
        assertSameValue('Historical observation', $historicalRow->observations);
    });

    $runner->add('E013 Phase 2 PDO projections fail closed on status isolation invalid booleans and duplicate Enrollment rows', function (): void {
        [$manager, $pdo] = enrollmentReportingFixture();
        $pdo->exec('UPDATE statuses SET status_type_id = 1 WHERE id = 11');
        reportingAssertThrows(static fn (): mixed => (new PdoStudentEnrollmentListQuery($manager))->fetch(101), RuntimeException::class);

        [$manager, $pdo] = enrollmentReportingFixture();
        $pdo->exec('UPDATE enrollments SET has_allergies = 2 WHERE id = 1002');
        reportingAssertThrows(static fn (): mixed => (new PdoStudentMedicalReportQuery($manager))->fetch(101), RuntimeException::class);

        [$manager, $pdo] = enrollmentReportingFixture();
        $pdo->exec("INSERT INTO enrollments (id, student_id, family_id, academic_period_id, status_id) VALUES (1099, 2, 999, 101, 11)");
        reportingAssertThrows(static fn (): mixed => (new PdoStudentEnrollmentListQuery($manager))->fetch(101), RuntimeException::class);
    });

    $runner->add('E013 Phase 2 PDO reporting is prepared read-only lock-free and leaves physical rows unchanged', function (): void {
        [$manager, $pdo] = enrollmentReportingFixture();
        $before = (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();
        (new PdoEnrollmentSummaryQuery($manager))->fetch(101);
        (new PdoStudentEnrollmentListQuery($manager))->fetch(101);
        (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch(101);
        (new PdoStudentBillingReportQuery($manager))->fetch(101);
        (new PdoStudentMedicalReportQuery($manager))->fetch(101);
        assertSameValue($before, (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn());

        foreach (glob(dirname(__DIR__) . '/app/Enrollment/Infrastructure/Reporting/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            assertSameValue(false, preg_match('/\b(?:INSERT|UPDATE|DELETE|FOR UPDATE|LOCK IN SHARE MODE)\b/i', $source) === 1);
            if (!str_ends_with($file, 'PdoEnrollmentReportingQuery.php')) {
                assertSameValue(true, str_contains($source, '->rows('));
            }
        }
    });
}

/** @return array{\Core\Database\ConnectionManager, PDO} */
function enrollmentReportingFixture(): array
{
    $manager = familySqliteManager();
    $pdo = $manager->connection();
    $pdo->exec('CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL, sort_order INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE document_types (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE academic_periods (id INTEGER PRIMARY KEY, code TEXT, name TEXT, starts_on TEXT, ends_on TEXT, status_id INTEGER)');
    $pdo->exec('CREATE TABLE grades (id INTEGER PRIMARY KEY, name TEXT, sort_order INTEGER)');
    $pdo->exec('CREATE TABLE sections (id INTEGER PRIMARY KEY, grade_id INTEGER, name TEXT)');
    $pdo->exec('CREATE TABLE persons (id INTEGER PRIMARY KEY, document_type_id INTEGER, document_number TEXT, first_name TEXT, middle_name TEXT, first_surname TEXT, second_surname TEXT, mobile_phone TEXT, landline_phone TEXT, email TEXT)');
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, person_id INTEGER, status_id INTEGER)');
    $pdo->exec('CREATE TABLE representatives (id INTEGER PRIMARY KEY, person_id INTEGER, work_phone TEXT, work_email TEXT, status_id INTEGER)');
    $pdo->exec('CREATE TABLE enrollments (id INTEGER PRIMARY KEY, student_id INTEGER, family_id INTEGER, academic_period_id INTEGER, status_id INTEGER, grade_id INTEGER, section_id INTEGER, billing_identification_type_id INTEGER, billing_identification_number TEXT, billing_legal_name TEXT, billing_address TEXT, billing_email TEXT, billing_phone TEXT, has_medical_condition INTEGER, medical_condition_detail TEXT, has_allergies INTEGER, allergy_detail TEXT, takes_permanent_medication INTEGER, medication_name TEXT, requires_special_care INTEGER, special_care_detail TEXT, has_medical_insurance INTEGER, insurance_provider TEXT, pediatrician_name TEXT, pediatrician_phone TEXT, medical_observations TEXT)');
    $pdo->exec('CREATE TABLE family_students (id INTEGER PRIMARY KEY, family_id INTEGER, student_id INTEGER, started_at TEXT, ended_at TEXT)');
    $pdo->exec('CREATE TABLE family_representatives (id INTEGER PRIMARY KEY, family_id INTEGER, representative_id INTEGER, started_at TEXT, ended_at TEXT, is_primary INTEGER, status_id INTEGER)');
    $pdo->exec('CREATE TABLE family_addresses (id INTEGER PRIMARY KEY, family_id INTEGER, main_street TEXT, street_number TEXT, secondary_street TEXT, sector TEXT, reference TEXT, status_id INTEGER)');
    $pdo->exec('CREATE TABLE student_address_assignments (id INTEGER PRIMARY KEY, family_id INTEGER, student_id INTEGER, family_address_id INTEGER, started_at TEXT, ended_at TEXT)');

    $pdo->exec("INSERT INTO status_types VALUES (1, 'GENERAL_STATUS'), (2, 'ENROLLMENT_STATUS')");
    $pdo->exec("INSERT INTO statuses VALUES (1,1,'ACTIVE',1),(2,1,'INACTIVE',2),(11,2,'DRAFT',1),(12,2,'SUBMITTED',2),(13,2,'COMPLETED',3),(14,2,'CANCELLED',4)");
    $pdo->exec("INSERT INTO document_types VALUES (1, 'National ID')");
    $pdo->exec("INSERT INTO academic_periods VALUES (100,'HIST','Historical','2025-09-01','2026-06-30',2),(101,'CURR','Current','2026-09-01','2027-06-30',1)");
    $pdo->exec("INSERT INTO grades VALUES (1,'Grade One',1),(2,'Grade Two',2)");
    $pdo->exec("INSERT INTO sections VALUES (1,1,'A'),(2,2,'B')");
    for ($id = 1; $id <= 6; $id++) {
        $pdo->exec("INSERT INTO persons VALUES ({$id},1,'STU-{$id}','Name{$id}',NULL,'Surname{$id}',NULL,NULL,NULL,NULL)");
        $status = $id === 6 ? 2 : 1;
        $pdo->exec("INSERT INTO students VALUES ({$id},{$id},{$status})");
    }
    $pdo->exec("INSERT INTO persons VALUES (70,1,'REP-70','Current',NULL,'Representative',NULL,'0990000000','022000000','personal@example.test')");
    $pdo->exec("INSERT INTO representatives VALUES (700,70,'022111111','work@example.test',1)");
    $pdo->exec("INSERT INTO family_students VALUES (1,500,2,'2026-01-01 00:00:00',NULL)");
    $pdo->exec("INSERT INTO family_representatives VALUES (1,500,700,'2026-01-01 00:00:00',NULL,1,1)");
    $pdo->exec("INSERT INTO family_addresses VALUES (900,500,'Current Street','10','Cross Street','Current Sector','Current reference',1)");
    $pdo->exec("INSERT INTO student_address_assignments VALUES (1,500,2,900,'2026-01-01 00:00:00',NULL)");

    $pdo->exec("INSERT INTO enrollments (id,student_id,family_id,academic_period_id,status_id,grade_id,section_id) VALUES (1001,2,999,101,11,1,1)");
    $pdo->exec("INSERT INTO enrollments VALUES (1002,3,999,101,12,1,NULL,1,'BILL-3','Billing Three','Billing Address','billing@example.test','0993333333',1,'Condition',1,'Allergy',1,'Medication',1,'Care',1,'Insurance','Doctor','0994444444','Observation')");
    $pdo->exec("INSERT INTO enrollments (id,student_id,family_id,academic_period_id,status_id,grade_id,section_id) VALUES (1003,4,999,101,13,NULL,NULL)");
    $pdo->exec("INSERT INTO enrollments (id,student_id,family_id,academic_period_id,status_id,grade_id,section_id) VALUES (1004,5,999,101,14,2,2)");
    $pdo->exec("INSERT INTO enrollments (id,student_id,family_id,academic_period_id,status_id,grade_id,section_id) VALUES (1005,6,999,101,11,NULL,NULL)");
    $pdo->exec("INSERT INTO enrollments VALUES (900,2,123,100,13,2,NULL,1,'HIST-2','Historical Billing','Old Address','old@example.test','0995555555',0,NULL,0,NULL,0,NULL,0,NULL,0,NULL,NULL,NULL,'Historical observation')");

    return [$manager, $pdo];
}

function reportingAssertThrows(callable $operation, string $expectedClass): void
{
    try {
        $operation();
    } catch (\Throwable $exception) {
        assertSameValue($expectedClass, $exception::class);

        return;
    }
    throw new RuntimeException('Expected exception ' . $expectedClass . '.');
}
