<?php

declare(strict_types=1);

use App\AcademicCore\Application\ActivateAcademicPeriod;
use App\AcademicCore\Application\DeactivateAcademicPeriod;
use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Application\GetNextActiveGrade;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPlacementReferenceProvider;
use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPeriodRepository;
use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Orchestration\CreateRepresentativeAccess;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\GetAuthorizedFamilies;
use App\IdentityAccess\Application\RepresentativeFamilyContextSession;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\IdentityAccess\Application\SelectAuthorizedFamily;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\Exception\FamilyContextNotAuthorized;
use App\IdentityAccess\Application\Orchestration\UpdatePersonWithRepresentativeUserSync;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\IdentityAccess\Infrastructure\Persistence\PdoTransactionManager;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Application\ActivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSubmissionSatisfaction;
use App\InstitutionalDocuments\Application\CompleteRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\CreateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\DeactivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\Dto\CompleteRepresentativeAcknowledgementsInput;
use App\InstitutionalDocuments\Application\Dto\CreateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Dto\UpdateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Exception\AcknowledgementRequirementNotFound;
use App\InstitutionalDocuments\Application\Exception\InvalidAcknowledgementConfirmation;
use App\InstitutionalDocuments\Application\GetAcknowledgementRequirements;
use App\InstitutionalDocuments\Application\UpdateAcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId as AcknowledgementAcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId as AcknowledgementRepresentativeId;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoAcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoRepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\CreateFamilyAddress;
use App\Family\Application\Dto\CreateFamilyAddressInput;
use App\Family\Application\GetFamily;
use App\Family\Application\GetFamilyResources;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyResourceStatus;
use App\Family\Domain\Exception\FamilyCodeAlreadyExists;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\DocumentTypeId as FamilyDocumentTypeId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\FamilyCode;
use App\Family\Domain\ValueObject\Geolocation;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentReference;
use App\Family\Infrastructure\Persistence\PdoFamilyRepository;
use App\Family\Infrastructure\Persistence\PdoDocumentTypeLookup;
use App\Family\Infrastructure\Persistence\PdoFamilyFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoFamilyResourceFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoRelationshipTypeLookup;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\UpdatePerson;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;
use App\Person\Infrastructure\Persistence\PdoPersonFormOptionsProvider;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use Tests\MariaDbBeforeTransactionRunner;
use Tests\MariaDbBulkImportWorkbookReader;
use Tests\MariaDbFailOneFamilyAfterSaveRepository;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use App\Representative\Infrastructure\Persistence\PdoRepresentativeRepository;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Application\CreateStudent;
use App\Student\Infrastructure\Persistence\PdoStudentRepository;
use App\Enrollment\Domain\Enrollment as EnrollmentAggregate;
use App\Enrollment\Domain\EnrollmentStatus as EnrollmentAggregateStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId as EnrollmentAcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement as EnrollmentAcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation as EnrollmentBillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId as EnrollmentAggregateId;
use App\Enrollment\Domain\ValueObject\FamilyId as EnrollmentFamilyId;
use App\Enrollment\Domain\ValueObject\GradeId as EnrollmentGradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId as EnrollmentIdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation as EnrollmentMedicalInformation;
use App\Enrollment\Domain\ValueObject\SectionId as EnrollmentSectionId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Enrollment\Domain\ValueObject\TransportInformation as EnrollmentTransportInformation;
use App\Enrollment\Infrastructure\Persistence\PdoEnrollmentRepository;
use App\Enrollment\Infrastructure\Persistence\PdoSubmittedEnrollmentIdQuery;
use App\Enrollment\Infrastructure\Reporting\PdoAcademicPeriodReportingQuery;
use App\Enrollment\Infrastructure\Reporting\PdoEnrollmentSummaryQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentBillingReportQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentEnrollmentListQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentMedicalReportQuery;
use App\Enrollment\Infrastructure\Reporting\PdoStudentRepresentativeDirectoryQuery;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\Dto\UpdateEnrollmentTransportInformationInput;
use App\Enrollment\Application\Exception\EnrollmentFamilyContextUnavailable;
use App\Enrollment\Application\StartEnrollmentDraft;
use App\Enrollment\Application\UpdateEnrollmentTransportInformation;
use App\Enrollment\Application\Submission\Dto\SubmitRepresentativeEnrollmentInput;
use App\Enrollment\Application\Submission\EnrollmentSubmissionValidator;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionContextUnavailable;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionNotReady;
use App\Enrollment\Application\Submission\SubmitRepresentativeEnrollment;
use App\Enrollment\Application\Administrative\CancelEnrollment;
use App\Enrollment\Application\Administrative\CompleteEnrollment;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentInvalidTransition;
use App\Enrollment\Application\Administrative\GetAdministrativeEnrollmentReview;
use App\Enrollment\Application\Administrative\ReopenEnrollment;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetEnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\GetStudentBillingReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\GetStudentMedicalReport;
use App\Enrollment\Application\Reporting\GetStudentRepresentativeDirectory;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\MigrationRunner;
use Core\Database\PdoTransactionRunner;
use Database\Seeders\AdminSeeder;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/database/seeders/AdminSeeder.php';

function assertIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, int|string|null> $parameters */
function assertMariaDbStatementRejected(PDO $connection, string $sql, array $parameters, string $message): void
{
    $rejected = false;
    try {
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
    } catch (PDOException) {
        $rejected = true;
    }

    assertIntegration($rejected, $message);
}

function diagnosticValue(mixed $value): string
{
    if (is_string($value)) {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '"(unrepresentable string)"' : $encoded;
    }

    if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
        return var_export($value, true);
    }

    return sprintf('[%s value]', get_debug_type($value));
}

/** @return array<string, list<array<string, mixed>>> */
function mariaDbRepresentativeFamilyAccessState(PDO $connection): array
{
    $state = [];
    foreach (['users', 'representatives', 'families', 'family_representatives'] as $table) {
        $state[$table] = $connection->query(
            sprintf('SELECT * FROM %s ORDER BY id', $table)
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    return $state;
}

/**
 * @param array<string, mixed>|false $row
 * @param array<string, mixed>|false $timeZone
 */
function mariaDbFamilyPersistenceDiagnostics(array|false $row, array|false $timeZone): string
{
    $expected = [
        'started_at' => '2026-08-01 15:11:12',
        'ended_at' => null,
        'is_primary' => 1,
        'status_type_code' => 'GENERAL_STATUS',
        'status_code' => 'ACTIVE',
    ];
    $lines = ['Family persistence did not store UTC seconds or resolve exact GENERAL_STATUS.'];

    foreach ($expected as $field => $expectedValue) {
        $actualValue = $row !== false && array_key_exists($field, $row) ? $row[$field] : null;
        $lines[] = sprintf(
            '%s: expected=%s; actual=%s; actual PHP type=%s',
            $field,
            diagnosticValue($expectedValue),
            diagnosticValue($actualValue),
            get_debug_type($actualValue),
        );
    }

    $sessionTimeZone = $timeZone !== false && array_key_exists('session_time_zone', $timeZone)
        ? $timeZone['session_time_zone']
        : null;
    $systemTimeZone = $timeZone !== false && array_key_exists('system_time_zone', $timeZone)
        ? $timeZone['system_time_zone']
        : null;
    $lines[] = 'MariaDB session timezone: ' . diagnosticValue($sessionTimeZone);
    $lines[] = sprintf(
        '@@session.time_zone: actual=%s; actual PHP type=%s',
        diagnosticValue($sessionTimeZone),
        get_debug_type($sessionTimeZone),
    );
    $lines[] = sprintf(
        '@@system_time_zone: actual=%s; actual PHP type=%s',
        diagnosticValue($systemTimeZone),
        get_debug_type($systemTimeZone),
    );

    return implode(PHP_EOL, $lines);
}

/** @return array{family: array<string, mixed>|false, representatives: array<int, array<string, mixed>>, students: array<int, array<string, mixed>>} */
function mariaDbFamilyPhysicalState(PDO $connection, int $familyId): array
{
    $family = $connection->prepare(
        'SELECT id, family_code, display_name, status_id, created_at, updated_at FROM families WHERE id = :id'
    );
    $family->execute([':id' => $familyId]);
    $representatives = $connection->prepare(
        'SELECT id, representative_id, relationship_type_id, is_primary, started_at, ended_at '
        . 'FROM family_representatives WHERE family_id = :familyId ORDER BY id'
    );
    $representatives->execute([':familyId' => $familyId]);
    $students = $connection->prepare(
        'SELECT id, student_id, started_at, ended_at '
        . 'FROM family_students WHERE family_id = :familyId ORDER BY id'
    );
    $students->execute([':familyId' => $familyId]);

    return [
        'family' => $family->fetch(PDO::FETCH_ASSOC),
        'representatives' => $representatives->fetchAll(PDO::FETCH_ASSOC),
        'students' => $students->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function isExpectedMariaDbLockException(PDOException $exception): bool
{
    $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);
    $message = strtolower($exception->errorInfo[2] ?? $exception->getMessage());

    return ($sqlState === 'HY000' && $driverCode === 1205 && str_contains($message, 'lock wait timeout'))
        || ($sqlState === '40001' && $driverCode === 1213 && str_contains($message, 'deadlock'));
}

function mariaDbPersonCollationDiagnostics(PDO $connection): string
{
    try {
        $session = $connection->query(
            'SELECT @@character_set_connection AS character_set_connection, '
            . '@@collation_connection AS collation_connection'
        )->fetch(PDO::FETCH_ASSOC);
        $columns = $connection->query(
            "SELECT column_name, collation_name FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = 'persons' "
            . "AND column_name IN ('document_number', 'identification_key') ORDER BY column_name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return sprintf(
            'character_set_connection=%s; collation_connection=%s; '
            . 'persons.document_number=%s; persons.identification_key=%s',
            $session['character_set_connection'] ?? '(unavailable)',
            $session['collation_connection'] ?? '(unavailable)',
            $columns['document_number'] ?? '(missing)',
            $columns['identification_key'] ?? '(missing)',
        );
    } catch (Throwable $exception) {
        return 'Collation diagnostics unavailable: ' . $exception->getMessage();
    }
}

function findPersonWithCollationDiagnostics(
    PDO $connection,
    PdoPersonRepository $repository,
    Identification $identification,
): ?Person {
    try {
        return $repository->findByIdentification($identification);
    } catch (PDOException $exception) {
        if (!str_contains(strtolower($exception->getMessage()), 'collation')) {
            throw $exception;
        }

        throw new RuntimeException(
            'Person identification lookup failed because of a MariaDB collation conflict. '
            . mariaDbPersonCollationDiagnostics($connection),
            previous: $exception,
        );
    }
}

/**
 * @param list<string> $databases
 * @return list<string>
 */
function dropDisposableDatabases(PDO $server, array $databases): array
{
    $failures = [];

    foreach (array_reverse($databases) as $database) {
        try {
            $server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        } catch (Throwable $exception) {
            $failures[] = sprintf('%s: %s', $database, $exception->getMessage());
        }
    }

    return $failures;
}

/** @return list<string> */
function expectedBaselineTables(): array
{
    $tables = [
        'academic_periods', 'acknowledgement_requirements', 'authorized_pickup_assignments', 'cantons',
        'document_types', 'education_levels', 'emergency_contact_assignments', 'enrollments',
        'families', 'family_addresses', 'family_authorized_pickups', 'family_emergency_contacts',
        'family_representatives', 'family_students', 'grades', 'marital_statuses', 'migrations',
        'parishes', 'persons', 'provinces',
        'relationship_types', 'representative_address_assignments', 'representatives', 'sections',
        'representative_acknowledgement_completions', 'representative_acknowledgements', 'sexes',
        'statuses', 'status_types', 'student_address_assignments', 'students', 'users',
    ];
    sort($tables);

    return $tables;
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 */
function schemaInventoryDifferenceMessage(array $expected, array $actual): string
{
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);

    $missing = array_values(array_diff($expected, $actual));
    $unexpected = array_values(array_diff($actual, $expected));

    return sprintf(
        "Clean migration inventory differs from the baseline.\n"
        . "Expected table count: %d\n"
        . "Actual table count: %d\n"
        . "Missing tables: %s\n"
        . "Unexpected tables: %s\n"
        . "Expected inventory: %s\n"
        . "Actual inventory: %s",
        count($expected),
        count($actual),
        $missing === [] ? '(none)' : implode(', ', $missing),
        $unexpected === [] ? '(none)' : implode(', ', $unexpected),
        implode(', ', $expected),
        implode(', ', $actual),
    );
}

function runMariaDbSubmissionSnapshotRemovalMigrationScenario(PDO $connection): void
{
    $removedTables = [
        'enrollment_submission_snapshots',
        'snapshot_addresses',
        'snapshot_authorized_pickups',
        'snapshot_emergency_contacts',
    ];

    $migration = new CreateRemoveSubmissionSnapshots();
    $migration->down($connection);

    $restoredTables = $connection->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() "
        . "AND table_name IN ('enrollment_submission_snapshots', 'snapshot_addresses', "
        . "'snapshot_authorized_pickups', 'snapshot_emergency_contacts') ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        $restoredTables === $removedTables,
        'MariaDB migration 010 down did not restore the exact legacy snapshot table inventory: '
        . implode(', ', $restoredTables)
    );

    $migration->up($connection);
    $remainingTables = $connection->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() "
        . "AND table_name IN ('enrollment_submission_snapshots', 'snapshot_addresses', "
        . "'snapshot_authorized_pickups', 'snapshot_emergency_contacts') ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        $remainingTables === [],
        'MariaDB migration 010 reapply left legacy snapshot tables: ' . implode(', ', $remainingTables)
    );
}

function assertMariaDbFamilyCodeSchema(PDO $connection): void
{
    $column = $connection->query(
        "SELECT data_type, character_maximum_length, character_set_name, collation_name, "
        . "is_nullable, column_default FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'families' AND column_name = 'family_code'"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $column !== false
        && $column['data_type'] === 'char'
        && (int) $column['character_maximum_length'] === 9
        && $column['character_set_name'] === 'ascii'
        && $column['collation_name'] === 'ascii_bin'
        && $column['is_nullable'] === 'NO'
        && $column['column_default'] === null,
        'MariaDB FamilyCode column does not match the exact CHAR(9) ASCII binary NOT NULL contract.'
    );

    $index = $connection->query(
        "SELECT non_unique, column_name, seq_in_index FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'families' "
        . "AND index_name = 'uq_families_family_code' ORDER BY seq_in_index"
    )->fetchAll(PDO::FETCH_ASSOC);
    assertIntegration(
        $index === [['non_unique' => 0, 'column_name' => 'family_code', 'seq_in_index' => 1]]
        || $index === [['non_unique' => '0', 'column_name' => 'family_code', 'seq_in_index' => '1']],
        'MariaDB FamilyCode UNIQUE index does not match the approved single-column contract.'
    );
}

function runMariaDbMigrationsThrough010(PDO $connection): void
{
    $migrationFiles = [
        '001_create_migrations_table.php',
        '002_create_status_schema.php',
        '003_create_reference_catalogs.php',
        '004_create_academic_core.php',
        '005_create_identity_and_roles.php',
        '006_create_family_management.php',
        '007_create_institutional_documents.php',
        '008_create_enrollment.php',
        '009_create_submission_snapshots.php',
        '010_remove_submission_snapshots.php',
    ];
    foreach ($migrationFiles as $migrationFile) {
        require_once dirname(__DIR__) . '/database/migrations/' . $migrationFile;
    }
    $migrations = [
        new CreateMigrationsTable(),
        new CreateStatusSchema(),
        new CreateReferenceCatalogs(),
        new CreateAcademicCore(),
        new CreateIdentityAndRoles(),
        new CreateFamilyManagement(),
        new CreateInstitutionalDocuments(),
        new CreateEnrollment(),
        new CreateSubmissionSnapshots(),
        new CreateRemoveSubmissionSnapshots(),
    ];
    foreach ($migrations as $migration) {
        $migration->up($connection);
        $record = $connection->prepare(
            'INSERT INTO migrations (migration, batch) VALUES (:migration, 1)'
        );
        $record->execute([':migration' => $migration->version()]);
    }
    foreach ([
        new \Database\Seeders\StatusTypeSeeder(),
        new \Database\Seeders\StatusSeeder(),
        new AdminSeeder(),
    ] as $seeder) {
        $seeder->run($connection);
    }
    assertIntegration(
        (int) $connection->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 10,
        'Legacy FamilyCode fixture did not stop at the exact migration-010 baseline.'
    );
}

function runMariaDbFamilyCodeLegacyUpgradeScenario(PDO $connection): void
{
    $migration = new CreateAddFamilyCodeToFamilies();

    $statusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    assertIntegration($statusId > 0, 'Legacy FamilyCode upgrade fixture requires GENERAL_STATUS ACTIVE.');
    $connection->exec(
        "INSERT INTO sexes (code, name, is_active) VALUES ('E015_LEGACY', 'E015 legacy fixture', TRUE)"
    );
    $sexId = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO relationship_types (code, name, is_active) "
        . "VALUES ('E015_LEGACY', 'E015 legacy fixture', TRUE)"
    );
    $relationshipTypeId = (int) $connection->lastInsertId();
    $insertPerson = $connection->prepare(
        'INSERT INTO persons (first_name, first_surname, birth_date, sex_id, status_id) '
        . 'VALUES (:firstName, :firstSurname, :birthDate, :sexId, :statusId)'
    );
    $insertPerson->execute([
        ':firstName' => 'Legacy', ':firstSurname' => 'Representative', ':birthDate' => '1980-01-01',
        ':sexId' => $sexId, ':statusId' => $statusId,
    ]);
    $representativePersonId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO representatives (person_id, status_id) VALUES (:personId, :statusId)'
    )->execute([':personId' => $representativePersonId, ':statusId' => $statusId]);
    $representativeId = (int) $connection->lastInsertId();
    $insertPerson->execute([
        ':firstName' => 'Legacy', ':firstSurname' => 'Student', ':birthDate' => '2015-01-01',
        ':sexId' => $sexId, ':statusId' => $statusId,
    ]);
    $studentPersonId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO students (person_id, institutional_code, admission_date, status_id) '
        . 'VALUES (:personId, :institutionalCode, :admissionDate, :statusId)'
    )->execute([
        ':personId' => $studentPersonId,
        ':institutionalCode' => 'E015-LEGACY-STUDENT',
        ':admissionDate' => '2025-09-01',
        ':statusId' => $statusId,
    ]);
    $studentId = (int) $connection->lastInsertId();

    $insert = $connection->prepare(
        'INSERT INTO families (display_name, status_id) VALUES (:displayName, :statusId)'
    );
    $legacyFamilies = [];
    foreach (['Legacy Family Alpha', 'Legacy Family Beta'] as $displayName) {
        $insert->execute([':displayName' => $displayName, ':statusId' => $statusId]);
        $id = (int) $connection->lastInsertId();
        assertIntegration($id > 0, 'Legacy Family fixture did not receive AUTO_INCREMENT identity.');
        $legacyFamilies[$id] = $displayName;
    }
    $familyIds = array_keys($legacyFamilies);
    $connection->prepare(
        'INSERT INTO family_representatives '
        . '(family_id, representative_id, relationship_type_id, is_primary, started_at) '
        . 'VALUES (:familyId, :representativeId, :relationshipTypeId, TRUE, :startedAt)'
    )->execute([
        ':familyId' => $familyIds[0],
        ':representativeId' => $representativeId,
        ':relationshipTypeId' => $relationshipTypeId,
        ':startedAt' => '2026-08-01 00:00:00',
    ]);
    $familyRepresentativeId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO family_students (family_id, student_id, started_at) '
        . 'VALUES (:familyId, :studentId, :startedAt)'
    )->execute([
        ':familyId' => $familyIds[1],
        ':studentId' => $studentId,
        ':startedAt' => '2026-08-01 00:00:00',
    ]);
    $familyStudentId = (int) $connection->lastInsertId();

    $migration->up($connection);
    assertMariaDbFamilyCodeSchema($connection);

    $rows = $connection->query(
        'SELECT id, family_code, display_name, status_id FROM families ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    assertIntegration(count($rows) === 2, 'FamilyCode legacy upgrade changed the Family row count.');
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        assertIntegration(
            array_key_exists($id, $legacyFamilies)
            && $row['family_code'] === sprintf('F%08d', $id)
            && $row['display_name'] === $legacyFamilies[$id]
            && (int) $row['status_id'] === $statusId,
            'FamilyCode legacy upgrade did not preserve data or deterministically backfill from FamilyId.'
        );
    }
    $preservedMemberships = $connection->query(
        'SELECT '
        . '(SELECT COUNT(*) FROM family_representatives WHERE id = ' . $familyRepresentativeId
        . ' AND family_id = ' . $familyIds[0] . ' AND representative_id = ' . $representativeId . ') + '
        . '(SELECT COUNT(*) FROM family_students WHERE id = ' . $familyStudentId
        . ' AND family_id = ' . $familyIds[1] . ' AND student_id = ' . $studentId . ')'
    )->fetchColumn();
    assertIntegration(
        (int) $preservedMemberships === 2,
        'FamilyCode legacy upgrade did not preserve membership identities and foreign-key ownership.'
    );

    $firstCode = (string) $rows[0]['family_code'];
    $exactCase = $connection->prepare('SELECT COUNT(*) FROM families WHERE family_code = :familyCode');
    $exactCase->execute([':familyCode' => strtolower($firstCode)]);
    assertIntegration(
        (int) $exactCase->fetchColumn() === 0,
        'FamilyCode legacy upgrade did not preserve exact ASCII-binary case behavior.'
    );
    assertMariaDbStatementRejected(
        $connection,
        'INSERT INTO families (family_code, display_name, status_id) '
            . 'VALUES (:familyCode, :displayName, :statusId)',
        [':familyCode' => $firstCode, ':displayName' => 'Duplicate FamilyCode', ':statusId' => $statusId],
        'MariaDB FamilyCode legacy upgrade did not enforce physical uniqueness.'
    );

    $migration->down($connection);
    $columnCountAfterRollback = (int) $connection->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name = 'families' AND column_name = 'family_code'"
    )->fetchColumn();
    $membershipCountAfterRollback = (int) $connection->query(
        'SELECT (SELECT COUNT(*) FROM family_representatives) + (SELECT COUNT(*) FROM family_students)'
    )->fetchColumn();
    assertIntegration(
        $columnCountAfterRollback === 0
        && (int) $connection->query('SELECT COUNT(*) FROM families')->fetchColumn() === 2
        && $membershipCountAfterRollback === 2,
        'Migration 011 rollback did not remove only FamilyCode while preserving legacy Families and memberships.'
    );
    $migration->up($connection);
    assertMariaDbFamilyCodeSchema($connection);
    assertIntegration(
        (int) $connection->query(
            "SELECT COUNT(*) FROM families WHERE family_code REGEXP '^F[0-9]{8}$'"
        )->fetchColumn() === 2,
        'Migration 011 reapply did not restore deterministic FamilyCodes after rollback.'
    );

    $nextBatch = (int) $connection->query(
        'SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations'
    )->fetchColumn();
    $recordMigration = $connection->prepare(
        'INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)'
    );
    $recordMigration->execute([
        ':migration' => '011_add_family_code_to_families',
        ':batch' => $nextBatch,
    ]);
}

function runMariaDbFamilyCodeRangeGuardScenario(PDO $connection): void
{
    $migration = new CreateAddFamilyCodeToFamilies();
    $statusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $connection->prepare(
        'INSERT INTO families (id, display_name, status_id) VALUES (100000000, :displayName, :statusId)'
    )->execute([':displayName' => 'Unrepresentable legacy Family', ':statusId' => $statusId]);

    $rejected = false;
    try {
        $migration->up($connection);
    } catch (RuntimeException $exception) {
        $rejected = $exception->getMessage() === 'FamilyCode backfill cannot represent an existing FamilyId.';
    }
    assertIntegration($rejected, 'Migration 011 did not fail closed for an unrepresentable legacy FamilyId.');

    $partialColumn = $connection->query(
        "SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name = 'families' AND column_name = 'family_code'"
    )->fetchColumn();
    $uniqueCount = (int) $connection->query(
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() "
        . "AND table_name = 'families' AND index_name = 'uq_families_family_code'"
    )->fetchColumn();
    assertIntegration(
        $partialColumn === 'YES' && $uniqueCount === 0,
        'Migration 011 range rejection advanced beyond its nullable staging column.'
    );
}

function runMariaDbEnrollmentPersistenceScenario(
    ConnectionManager $manager,
    PDO $connection,
    int $studentAId,
    int $studentBId,
    int $familyId,
    int $representativeId,
    int $periodAId,
    int $periodBId,
    int $periodCId,
    int $generalStatusId,
): void {
    foreach ([
        'studentAId' => $studentAId,
        'studentBId' => $studentBId,
        'familyId' => $familyId,
        'representativeId' => $representativeId,
        'periodAId' => $periodAId,
        'periodBId' => $periodBId,
        'periodCId' => $periodCId,
        'generalStatusId' => $generalStatusId,
    ] as $label => $value) {
        assertIntegration($value > 0, 'E010 MariaDB fixture requires positive ' . $label . '.');
    }

    $connection->prepare(
        'INSERT INTO grades (code, name, sort_order, status_id) '
        . 'VALUES (:code, :name, :sortOrder, :statusId)'
    )->execute([
        ':code' => 'E010_GRADE',
        ':name' => 'E010 Grade',
        ':sortOrder' => 32000,
        ':statusId' => $generalStatusId,
    ]);
    $gradeId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO sections (code, name, status_id) VALUES (:code, :name, :statusId)'
    )->execute([
        ':code' => 'E010_SECTION',
        ':name' => 'E010 Section',
        ':statusId' => $generalStatusId,
    ]);
    $sectionId = (int) $connection->lastInsertId();
    assertIntegration($gradeId > 0 && $sectionId > 0, 'E010 Grade and Section fixtures require AUTO_INCREMENT.');

    $repository = new PdoEnrollmentRepository($manager);
    $draft = EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentAId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodAId),
        new DateTimeImmutable('2026-08-18 09:10:11.654321-05:00'),
        new EnrollmentAcademicPlacement(new EnrollmentGradeId($gradeId), new EnrollmentSectionId($sectionId)),
        new EnrollmentBillingInformation(
            new EnrollmentIdentificationTypeId(1),
            '0912345678',
            'Familia Persistencia Ñ',
            'Av. Principal 123',
            'billing@example.test',
            '+593 99 000 0000',
        ),
        new EnrollmentMedicalInformation(
            true, 'Condición controlada', false, null, true, 'Medicamento',
            false, null, true, 'Seguro', 'Pediatra', '020000003', 'Observación médica',
        ),
        new EnrollmentTransportInformation(true),
        true,
    );
    $persistedDraft = $repository->save($draft);
    $enrollmentId = $persistedDraft->id();
    assertIntegration(
        $draft->id() === null && $enrollmentId !== null && $enrollmentId->value() > 0,
        'MariaDB Enrollment insert did not preserve new identity or generate AUTO_INCREMENT.'
    );
    $roundtrip = $repository->findByStudentAndAcademicPeriod(
        new EnrollmentStudentId($studentAId),
        new EnrollmentAcademicPeriodId($periodAId),
    );
    assertIntegration(
        $roundtrip !== null
        && $roundtrip->id()?->equals($enrollmentId)
        && $roundtrip->academicPlacement()?->gradeId()->value() === $gradeId
        && $roundtrip->academicPlacement()?->sectionId()?->value() === $sectionId
        && $roundtrip->billingInformation()?->legalName() === 'Familia Persistencia Ñ'
        && $roundtrip->medicalInformation()?->medicalConditionDetail() === 'Condición controlada'
        && $roundtrip->transportInformation()?->requiresInstitutionalTransport() === true
        && $roundtrip->isAuthorizedToLeaveAlone()
        && $roundtrip->startedAt()->format('Y-m-d H:i:s P') === '2026-08-18 14:10:11 +00:00',
        'MariaDB Enrollment full Draft roundtrip or UTC normalization failed.'
    );

    $duplicateRejected = false;
    try {
        $repository->save(EnrollmentAggregate::startDraft(
            new EnrollmentStudentId($studentAId),
            new EnrollmentFamilyId($familyId),
            new EnrollmentAcademicPeriodId($periodAId),
            new DateTimeImmutable('2026-08-18 14:10:11+00:00'),
        ));
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    assertIntegration($duplicateRejected, 'MariaDB accepted duplicate Student plus AcademicPeriod Enrollment.');

    $changedOwnership = EnrollmentAggregate::reconstitute(
        $enrollmentId,
        new EnrollmentStudentId($studentBId),
        $persistedDraft->familyId(),
        $persistedDraft->academicPeriodId(),
        $persistedDraft->status(),
        $persistedDraft->academicPlacement(),
        $persistedDraft->billingInformation(),
        $persistedDraft->medicalInformation(),
        $persistedDraft->transportInformation(),
        $persistedDraft->isAuthorizedToLeaveAlone(),
        $persistedDraft->startedAt(),
        null,
        null,
        null,
    );
    $ownershipRejected = false;
    try {
        $repository->save($changedOwnership);
    } catch (RuntimeException) {
        $ownershipRejected = true;
    }
    $changedStartedAt = EnrollmentAggregate::reconstitute(
        $enrollmentId,
        $persistedDraft->studentId(),
        $persistedDraft->familyId(),
        $persistedDraft->academicPeriodId(),
        $persistedDraft->status(),
        $persistedDraft->academicPlacement(),
        $persistedDraft->billingInformation(),
        $persistedDraft->medicalInformation(),
        $persistedDraft->transportInformation(),
        $persistedDraft->isAuthorizedToLeaveAlone(),
        $persistedDraft->startedAt()->modify('+1 second'),
        null,
        null,
        null,
    );
    $startedAtRejected = false;
    try {
        $repository->save($changedStartedAt);
    } catch (RuntimeException) {
        $startedAtRejected = true;
    }
    assertIntegration(
        $ownershipRejected && $startedAtRejected,
        'MariaDB Enrollment update accepted changed ownership or started_at.'
    );

    $persistedDraft->submit(new DateTimeImmutable('2026-08-18 15:00:01.987654+00:00'));
    $submitted = $repository->save($persistedDraft);
    assertIntegration(
        $submitted->status() === EnrollmentAggregateStatus::Submitted
        && $submitted->submittedAt()?->format('Y-m-d H:i:s P') === '2026-08-18 15:00:01 +00:00',
        'MariaDB Enrollment submission status or UTC roundtrip failed.'
    );

    $submitted->reopen();
    $submitted->updateLeaveAloneAuthorization(false);
    $reopened = $repository->save($submitted);
    assertIntegration(
        $reopened->status() === EnrollmentAggregateStatus::Draft
        && $reopened->submittedAt()?->format('Y-m-d H:i:s P') === '2026-08-18 15:00:01 +00:00',
        'MariaDB Enrollment reopen did not preserve prior SubmittedAt.'
    );
    $reopened->submit(new DateTimeImmutable('2026-08-18 16:00:02+00:00'));
    $resubmitted = $repository->save($reopened);
    assertIntegration(
        $resubmitted->status() === EnrollmentAggregateStatus::Submitted
        && $resubmitted->submittedAt()?->format('Y-m-d H:i:s P') === '2026-08-18 16:00:02 +00:00',
        'MariaDB Enrollment resubmission did not replace SubmittedAt.'
    );

    $resubmitted->complete(new DateTimeImmutable('2026-08-18 17:00:03+00:00'));
    $completed = $repository->save($resubmitted);
    assertIntegration(
        $completed->status() === EnrollmentAggregateStatus::Completed
        && $completed->completedAt()?->format('Y-m-d H:i:s P') === '2026-08-18 17:00:03 +00:00',
        'MariaDB Enrollment Completion roundtrip failed.'
    );

    $cancelled = EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentBId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodBId),
        new DateTimeImmutable('2026-08-18 18:00:00+00:00'),
    );
    $cancelled->cancel(new DateTimeImmutable('2026-08-18 18:30:00+00:00'));
    $cancelled = $repository->save($cancelled);
    assertIntegration(
        $cancelled->status() === EnrollmentAggregateStatus::Cancelled
        && $cancelled->cancelledAt()?->format('Y-m-d H:i:s P') === '2026-08-18 18:30:00 +00:00',
        'MariaDB Enrollment Cancellation roundtrip failed.'
    );

    $completedStatusId = (int) $connection->query(
        "SELECT s.id FROM statuses s JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'ENROLLMENT_STATUS' AND s.code = 'COMPLETED'"
    )->fetchColumn();
    $physicalRoot = $connection->query(
        'SELECT st.code AS status_type_code, s.code AS status_code, e.started_at, e.submitted_at, e.completed_at '
        . 'FROM enrollments e JOIN statuses s ON s.id = e.status_id '
        . 'JOIN status_types st ON st.id = s.status_type_id WHERE e.id = ' . $enrollmentId->value()
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $physicalRoot !== false
        && $physicalRoot['status_type_code'] === 'ENROLLMENT_STATUS'
        && $physicalRoot['status_code'] === 'COMPLETED'
        && $physicalRoot['started_at'] === '2026-08-18 14:10:11'
        && $physicalRoot['submitted_at'] === '2026-08-18 16:00:02'
        && $physicalRoot['completed_at'] === '2026-08-18 17:00:03',
        'MariaDB Enrollment physical root did not preserve exact status type and UTC seconds.'
    );
    $connection->exec('UPDATE enrollments SET status_id = ' . $generalStatusId . ' WHERE id = ' . $enrollmentId->value());
    $wrongStatusRejected = false;
    try {
        $repository->findById($enrollmentId);
    } catch (RuntimeException) {
        $wrongStatusRejected = true;
    }
    $connection->exec('UPDATE enrollments SET status_id = ' . $completedStatusId . ' WHERE id = ' . $enrollmentId->value());
    assertIntegration($wrongStatusRejected, 'MariaDB Enrollment accepted status outside ENROLLMENT_STATUS.');

    $connection->beginTransaction();
    $externalDraft = EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentAId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodBId),
        new DateTimeImmutable('2026-08-19 09:00:00+00:00'),
    );
    $externalPersisted = $repository->save($externalDraft);
    assertIntegration(
        $connection->inTransaction() && $externalPersisted->id() !== null,
        'Enrollment Repository committed or lost caller transaction ownership.'
    );
    $connection->rollBack();
    assertIntegration(
        $repository->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId($studentAId),
            new EnrollmentAcademicPeriodId($periodBId),
        ) === null,
        'Caller rollback did not remove externally transacted Enrollment.'
    );

    $connection->exec('DROP TRIGGER IF EXISTS e010_reject_enrollment');
    $connection->exec(
        "CREATE TRIGGER e010_reject_enrollment BEFORE INSERT ON enrollments "
        . "FOR EACH ROW BEGIN IF NEW.started_at = '2026-08-19 10:00:00' THEN SIGNAL SQLSTATE '45000' "
        . "SET MESSAGE_TEXT = 'E010 forced Enrollment failure'; END IF; END"
    );
    try {
        $failedNew = EnrollmentAggregate::startDraft(
            new EnrollmentStudentId($studentBId),
            new EnrollmentFamilyId($familyId),
            new EnrollmentAcademicPeriodId($periodCId),
            new DateTimeImmutable('2026-08-19 10:00:00+00:00'),
        );
        $newFailureObserved = false;
        try {
            $repository->save($failedNew);
        } catch (PDOException) {
            $newFailureObserved = true;
        }
        assertIntegration(
            $newFailureObserved
            && $repository->findByStudentAndAcademicPeriod(
                new EnrollmentStudentId($studentBId),
                new EnrollmentAcademicPeriodId($periodCId),
            ) === null,
            'Failed MariaDB Enrollment insert left a partial root.'
        );

        $connection->beginTransaction();
        $externalFailed = EnrollmentAggregate::startDraft(
            new EnrollmentStudentId($studentAId),
            new EnrollmentFamilyId($familyId),
            new EnrollmentAcademicPeriodId($periodBId),
            new DateTimeImmutable('2026-08-19 10:00:00+00:00'),
        );
        $externalFailureObserved = false;
        try {
            $repository->save($externalFailed);
        } catch (PDOException) {
            $externalFailureObserved = true;
        }
        assertIntegration(
            $externalFailureObserved
            && $connection->inTransaction()
            && (int) $connection->query(
                'SELECT COUNT(*) FROM enrollments WHERE student_id = ' . $studentAId
                . ' AND academic_period_id = ' . $periodBId
            )->fetchColumn() === 0,
            'Failed external Enrollment save changed caller transaction ownership or persisted a partial root.'
        );
        $connection->rollBack();
        assertIntegration(
            $repository->findByStudentAndAcademicPeriod(
                new EnrollmentStudentId($studentAId),
                new EnrollmentAcademicPeriodId($periodBId),
            ) === null,
            'Caller rollback did not remove failed external Enrollment persistence state.'
        );
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        $connection->exec('DROP TRIGGER IF EXISTS e010_reject_enrollment');
    }
}

function runMariaDbEnrollmentApplicationConcurrencyScenario(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    int $studentAId,
    int $studentBId,
    int $familyId,
    int $periodAId,
    int $periodBId,
    int $periodCId,
    int $generalStatusId,
): void {
    $repositoryA = new PdoEnrollmentRepository($managerA);
    $repositoryB = new PdoEnrollmentRepository($managerB);

    $gradeId = (int) $connectionA->query(
        "SELECT id FROM grades WHERE code = 'E010_GRADE'"
    )->fetchColumn();
    $sectionId = (int) $connectionA->query(
        "SELECT id FROM sections WHERE code = 'E010_SECTION'"
    )->fetchColumn();
    $inactiveStatusId = (int) $connectionA->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    assertIntegration(
        $gradeId > 0 && $sectionId > 0 && $inactiveStatusId > 0,
        'E010 Phase 4 Academic reference fixtures were not available.'
    );

    $connectionA->prepare(
        'INSERT INTO grades (code, name, sort_order, status_id) '
        . 'VALUES (:code, :name, :sortOrder, :statusId)'
    )->execute([
        ':code' => 'E010_P4_INACTIVE_GRADE',
        ':name' => 'E010 Phase 4 Inactive Grade',
        ':sortOrder' => 32001,
        ':statusId' => $inactiveStatusId,
    ]);
    $connectionA->prepare(
        'INSERT INTO grades (code, name, sort_order, status_id) '
        . 'VALUES (:code, :name, :sortOrder, :statusId)'
    )->execute([
        ':code' => 'E010_P4_NEXT_GRADE',
        ':name' => 'E010 Phase 4 Next Grade',
        ':sortOrder' => 32002,
        ':statusId' => $generalStatusId,
    ]);
    $nextGradeId = (int) $connectionA->lastInsertId();
    $academicReferences = new PdoAcademicPlacementReferenceProvider($managerA);
    $nextGrade = (new GetNextActiveGrade($academicReferences))->handle($gradeId);
    assertIntegration(
        $academicReferences->findGradeById($gradeId)?->id === $gradeId
        && $academicReferences->findSectionById($sectionId)?->id === $sectionId
        && $nextGrade?->id === $nextGradeId
        && $nextGrade->sortOrder === 32002
        && $nextGrade->status === 'ACTIVE',
        'E010 Phase 4 Academic Core Grade Section boundary or next ACTIVE Grade ordering failed.'
    );

    $billingA = new EnrollmentBillingInformation(
        new EnrollmentIdentificationTypeId(1),
        '0911111111',
        'Billing A',
        'Address A',
        'billing-a@example.test',
        'Phone A',
    );
    $billingB = new EnrollmentBillingInformation(
        new EnrollmentIdentificationTypeId(1),
        '0922222222',
        'Billing B',
        'Address B',
        'billing-b@example.test',
        'Phone B',
    );
    $medicalA = new EnrollmentMedicalInformation(
        false, null, false, null, false, null, false, null, false, null,
        'Pediatrician A', null, 'Medical A',
    );
    $medicalB = new EnrollmentMedicalInformation(
        true, 'Condition B', true, 'Allergy B', true, 'Medication B', true, 'Care B', true, 'Insurance B',
        'Pediatrician B', 'Phone B', 'Medical B',
    );

    $draftA = $repositoryA->save(EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentAId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodBId),
        new DateTimeImmutable('2026-08-21 15:00:00+00:00'),
        new EnrollmentAcademicPlacement(new EnrollmentGradeId($gradeId), new EnrollmentSectionId($sectionId)),
        $billingA,
        $medicalA,
        new EnrollmentTransportInformation(true),
        false,
    ));
    $draftB = $repositoryA->save(EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentBId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodAId),
        new DateTimeImmutable('2026-08-21 15:01:00+00:00'),
    ));
    $draftAId = $draftA->id() ?? throw new RuntimeException('E010 Phase 4 Draft A identity missing.');
    $draftBId = $draftB->id() ?? throw new RuntimeException('E010 Phase 4 Draft B identity missing.');

    $connectionA->beginTransaction();
    $lockedA = $repositoryA->findByIdForUpdate($draftAId);
    assertIntegration(
        $lockedA !== null && $connectionA->inTransaction(),
        'E010 Phase 4 T1 did not lock Draft A root.'
    );
    $lockedA->updateBillingInformation($billingB);
    $repositoryA->save($lockedA);

    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $sameRootBlocked = false;
    try {
        $connectionB->beginTransaction();
        $repositoryB->findByIdForUpdate($draftAId);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'E010 Phase 4 same-root contention failed for a non-lock reason.',
                previous: $exception,
            );
        }
        $sameRootBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
    }
    assertIntegration($sameRootBlocked, 'E010 Phase 4 competing Draft mutation did not wait for root lock.');
    $connectionA->commit();

    $connectionB->beginTransaction();
    $postCommit = $repositoryB->findByIdForUpdate($draftAId);
    assertIntegration(
        $postCommit?->billingInformation()?->legalName() === 'Billing B',
        'E010 Phase 4 T2 did not load committed post-T1 state under lock.'
    );
    $postCommit->updateMedicalInformation($medicalB);
    $repositoryB->save($postCommit);
    $connectionB->commit();
    $serialized = $repositoryA->findById($draftAId);
    assertIntegration(
        $serialized?->billingInformation()?->legalName() === 'Billing B'
        && $serialized->medicalInformation()?->observations() === 'Medical B',
        'E010 Phase 4 same-Enrollment serialization lost Billing or Medical state.'
    );

    $connectionA->beginTransaction();
    $repositoryA->findByIdForUpdate($draftAId);
    $unrelated = (new UpdateEnrollmentTransportInformation(
        $repositoryB,
        new PdoTransactionRunner($managerB),
    ))->handle(new UpdateEnrollmentTransportInformationInput(
        $draftBId->value(),
        $studentBId,
        $familyId,
        $periodAId,
        true,
    ));
    assertIntegration(
        $unrelated->transportInformation?->requiresInstitutionalTransport === true
        && $connectionA->inTransaction(),
        'E010 Phase 4 lock on Enrollment A blocked or corrupted Enrollment B.'
    );
    $connectionA->rollBack();

    $connectionA->beginTransaction();
    $rollbackCandidate = $repositoryA->findByIdForUpdate($draftAId);
    $rollbackCandidate?->updateLeaveAloneAuthorization(true);
    if ($rollbackCandidate !== null) {
        $repositoryA->save($rollbackCandidate);
    }
    $rollbackBlocked = false;
    try {
        $connectionB->beginTransaction();
        $repositoryB->findByIdForUpdate($draftAId);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'E010 Phase 4 rollback contention failed for a non-lock reason.',
                previous: $exception,
            );
        }
        $rollbackBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
    }
    $connectionA->rollBack();
    $afterRollback = (new UpdateEnrollmentTransportInformation(
        $repositoryB,
        new PdoTransactionRunner($managerB),
    ))->handle(new UpdateEnrollmentTransportInformationInput(
        $draftAId->value(),
        $studentAId,
        $familyId,
        $periodBId,
        false,
    ));
    assertIntegration(
        $rollbackBlocked
        && !$afterRollback->isAuthorizedToLeaveAlone
        && $afterRollback->transportInformation?->requiresInstitutionalTransport === false,
        'E010 Phase 4 rollback did not release lock or preserve pre-transaction state.'
    );

    $raceStudent = new EnrollmentStudentId($studentAId);
    $racePeriod = new EnrollmentAcademicPeriodId($periodCId);
    $raceFamilyId = (new PdoFamilyRepository($managerA))->findActiveByStudentId(
        new FamilyStudentReference($studentAId),
    )?->id()?->value() ?? 0;
    assertIntegration(
        $raceFamilyId > 0
        &&
        $repositoryA->findByStudentAndAcademicPeriod($raceStudent, $racePeriod) === null
        && $repositoryB->findByStudentAndAcademicPeriod($raceStudent, $racePeriod) === null,
        'E010 Phase 4 concurrent initialization fixture was not empty for both connections.'
    );
    $staleDraft = EnrollmentAggregate::startDraft(
        $raceStudent,
        new EnrollmentFamilyId($raceFamilyId),
        $racePeriod,
        new DateTimeImmutable('2026-08-21 16:00:00+00:00'),
    );
    $clock = new class implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-21 16:00:01+00:00');
        }
    };
    $created = (new StartEnrollmentDraft(
        new PdoStudentRepository($managerA),
        new PdoFamilyRepository($managerA),
        new PdoAcademicPeriodRepository($managerA),
        $repositoryA,
        $academicReferences,
        $clock,
        new PdoTransactionRunner($managerA),
    ))->handle(new StartEnrollmentDraftInput($studentAId, $raceFamilyId, $periodCId));
    $raceRejected = false;
    try {
        $repositoryB->save($staleDraft);
    } catch (PDOException) {
        $raceRejected = true;
    }
    $raceCount = $connectionA->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $raceCount->execute([':studentId' => $studentAId, ':periodId' => $periodCId]);
    assertIntegration(
        $created->id > 0
        && $raceRejected
        && (int) $raceCount->fetchColumn() === 1,
        'E010 Phase 4 concurrent initialization did not preserve exactly one Enrollment root.'
    );
}

function runMariaDbEnrollmentActiveFamilyCaptureScenario(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    int $studentAId,
    int $studentBId,
    int $representativeId,
): void {
    $familyRepositoryA = new PdoFamilyRepository($managerA);
    $familyRepositoryB = new PdoFamilyRepository($managerB);
    $enrollmentRepositoryA = new PdoEnrollmentRepository($managerA);
    $academicReferences = new PdoAcademicPlacementReferenceProvider($managerA);
    $clock = new class implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-21 18:00:00+00:00');
        }
    };
    $familyAId = $familyRepositoryA->findActiveByStudentId(
        new FamilyStudentReference($studentAId),
    )?->id()?->value() ?? 0;
    assertIntegration($familyAId > 0, 'E010 corrective authoritative Family A fixture is unavailable.');

    $inactiveStatusId = (int) $connectionA->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    assertIntegration($inactiveStatusId > 0, 'E010 corrective AcademicPeriod status fixture is unavailable.');

    $periodIds = [];
    $periodInsert = $connectionA->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    foreach ([
        'START_FIRST',
        'STALE_NO_ACTIVE',
        'STALE_CURRENT_FAMILY',
        'CURRENT_FAMILY',
        'ROLLBACK_RELEASE',
    ] as $index => $code) {
        $periodInsert->execute([
            ':code' => 'E010_FAMILY_CAPTURE_' . $code,
            ':name' => 'E010 Family Capture ' . $code,
            ':startsOn' => sprintf('203%d-01-01', $index + 1),
            ':endsOn' => sprintf('203%d-12-31', $index + 1),
            ':statusId' => $inactiveStatusId,
        ]);
        $periodIds[$code] = (int) $connectionA->lastInsertId();
        assertIntegration($periodIds[$code] > 0, 'E010 corrective AcademicPeriod identity was not generated.');
    }

    $studentBFamily = $familyRepositoryA->findActiveByStudentId(
        new FamilyStudentReference($studentBId),
    );
    if ($studentBFamily === null) {
        $familyA = $familyRepositoryA->findById(new FamilyId($familyAId));
        if ($familyA === null) {
            throw new RuntimeException('E010 corrective Family A fixture is unavailable.');
        }
        $familyA->addStudent(
            new FamilyStudentReference($studentBId),
            new DateTimeImmutable('2026-08-21 17:59:00+00:00'),
        );
        $familyRepositoryA->save($familyA);
    } elseif ($studentBFamily->id()?->value() !== $familyAId) {
        throw new RuntimeException('E010 corrective Student B belongs to an unexpected active Family.');
    }


    $activeMembership = $connectionA->prepare(
        'SELECT id FROM family_students WHERE active_student_id = :studentId'
    );
    $activeMembership->execute([':studentId' => $studentAId]);
    $studentAMembershipId = (int) $activeMembership->fetchColumn();
    $activeMembership->execute([':studentId' => $studentBId]);
    $studentBMembershipId = (int) $activeMembership->fetchColumn();
    assertIntegration(
        $studentAMembershipId > 0 && $studentBMembershipId > 0,
        'E010 corrective active FamilyStudent fixtures are unavailable.'
    );

    $lockedStudentAFamilyId = null;
    $lockedStudentBFamilyId = null;
    try {
        $connectionA->beginTransaction();
        $lockedStudentA = $familyRepositoryA->findActiveByStudentIdForUpdate(
            new FamilyStudentReference($studentAId),
        );
        $connectionB->beginTransaction();
        $lockedStudentB = $familyRepositoryB->findActiveByStudentIdForUpdate(
            new FamilyStudentReference($studentBId),
        );
        $lockedStudentAFamilyId = $lockedStudentA?->id()?->value();
        $lockedStudentBFamilyId = $lockedStudentB?->id()?->value();
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }
    assertIntegration(
        $lockedStudentAFamilyId === $familyAId
        && $lockedStudentBFamilyId === $familyAId,
        sprintf(
            'E010 corrective FamilyStudent lock leaked across unrelated Students: expected=%d, A=%s, B=%s.',
            $familyAId,
            diagnosticValue($lockedStudentAFamilyId),
            diagnosticValue($lockedStudentBFamilyId),
        )
    );

    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $startFirstBlocked = false;
    $blockingFamilyRepository = new class(
        $familyRepositoryA,
        function () use (
            $connectionB,
            $studentAMembershipId,
            &$startFirstBlocked,
        ): void {
            try {
                $connectionB->beginTransaction();
                $statement = $connectionB->prepare(
                    'UPDATE family_students SET ended_at = :endedAt WHERE id = :id'
                );
                $statement->execute([
                    ':endedAt' => '2026-08-21 18:01:00',
                    ':id' => $studentAMembershipId,
                ]);
            } catch (PDOException $exception) {
                if (!isExpectedMariaDbLockException($exception)) {
                    throw new RuntimeException(
                        'E010 corrective Start-first contention failed for a non-lock reason.',
                        previous: $exception,
                    );
                }
                $startFirstBlocked = true;
            } finally {
                if ($connectionB->inTransaction()) {
                    $connectionB->rollBack();
                }
            }
        },
    ) implements FamilyRepository {
        private bool $afterLockCalled = false;

        public function __construct(
            private readonly FamilyRepository $delegate,
            private readonly \Closure $afterLock,
        ) {
        }

        public function findById(FamilyId $id): ?Family
        {
            return $this->delegate->findById($id);
        }

        public function findByIdForUpdate(FamilyId $id): ?Family
        {
            return $this->delegate->findByIdForUpdate($id);
        }

        public function findByCode(\App\Family\Domain\ValueObject\FamilyCode $familyCode): ?Family
        {
            return $this->delegate->findByCode($familyCode);
        }

        public function findByCodeForUpdate(\App\Family\Domain\ValueObject\FamilyCode $familyCode): ?Family
        {
            return $this->delegate->findByCodeForUpdate($familyCode);
        }

        public function findActiveByRepresentativeId(FamilyRepresentativeReference $representativeId): array
        {
            return $this->delegate->findActiveByRepresentativeId($representativeId);
        }

        public function findActiveByStudentId(FamilyStudentReference $studentId): ?Family
        {
            return $this->delegate->findActiveByStudentId($studentId);
        }

        public function findActiveByStudentIdForUpdate(FamilyStudentReference $studentId): ?Family
        {
            $family = $this->delegate->findActiveByStudentIdForUpdate($studentId);
            if (!$this->afterLockCalled) {
                $this->afterLockCalled = true;
                ($this->afterLock)();
            }

            return $family;
        }

        public function findActiveByRepresentativeAndFamilyForUpdate(
            FamilyRepresentativeReference $representativeId,
            FamilyId $familyId,
        ): ?Family {
            return $this->delegate->findActiveByRepresentativeAndFamilyForUpdate($representativeId, $familyId);
        }

        public function save(Family $family): Family
        {
            return $this->delegate->save($family);
        }
    };
    $startFirst = (new StartEnrollmentDraft(
        new PdoStudentRepository($managerA),
        $blockingFamilyRepository,
        new PdoAcademicPeriodRepository($managerA),
        $enrollmentRepositoryA,
        $academicReferences,
        $clock,
        new PdoTransactionRunner($managerA),
    ))->handle(new StartEnrollmentDraftInput(
        $studentAId,
        $familyAId,
        $periodIds['START_FIRST'],
    ));
    assertIntegration(
        $startFirstBlocked
        && $startFirst->familyId === $familyAId,
        'E010 corrective Start-first did not serialize Enrollment before membership end.'
    );

    $connectionB->beginTransaction();
    $endStudentA = $connectionB->prepare(
        'UPDATE family_students SET ended_at = :endedAt WHERE id = :id AND ended_at IS NULL'
    );
    $endStudentA->execute([
        ':endedAt' => '2026-08-21 18:01:00',
        ':id' => $studentAMembershipId,
    ]);
    assertIntegration($endStudentA->rowCount() === 1, 'E010 corrective blocked membership did not continue.');
    $connectionB->commit();

    $startFirstRow = $connectionA->prepare(
        'SELECT family_id FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $startFirstRow->execute([
        ':studentId' => $studentAId,
        ':periodId' => $periodIds['START_FIRST'],
    ]);
    assertIntegration(
        (int) $startFirstRow->fetchColumn() === $familyAId,
        'E010 corrective Start-first did not preserve the serialized historical Family context.'
    );


    $startWithFamily = static function (
        int $familyId,
        int $periodId,
    ) use (
        $managerA,
        $enrollmentRepositoryA,
        $academicReferences,
        $clock,
        $studentAId,
    ): void {
        (new StartEnrollmentDraft(
            new PdoStudentRepository($managerA),
            new PdoFamilyRepository($managerA),
            new PdoAcademicPeriodRepository($managerA),
            $enrollmentRepositoryA,
            $academicReferences,
            $clock,
            new PdoTransactionRunner($managerA),
        ))->handle(new StartEnrollmentDraftInput($studentAId, $familyId, $periodId));
    };

    $noActiveRejected = false;
    try {
        $startWithFamily($familyAId, $periodIds['STALE_NO_ACTIVE']);
    } catch (EnrollmentFamilyContextUnavailable) {
        $noActiveRejected = true;
    }
    assertIntegration(
        $noActiveRejected
        && mariaDbEnrollmentCount($connectionA, $studentAId, $periodIds['STALE_NO_ACTIVE']) === 0,
        'E010 corrective membership-change-first persisted a historical Family without active membership.'
    );

    $familyB = Family::create(
        \Tests\FamilyCodeTestFactory::next(),
        new DisplayName('E010 Corrective Family B'),
        FamilyStatus::Active,
        new FamilyRepresentativeReference($representativeId),
        new RelationshipTypeId(1),
        new DateTimeImmutable('2026-08-21 18:02:00+00:00'),
    );
    $familyB->addStudent(
        new FamilyStudentReference($studentAId),
        new DateTimeImmutable('2026-08-21 18:02:00+00:00'),
    );
    $persistedFamilyB = $familyRepositoryA->save($familyB);
    $familyBId = $persistedFamilyB->id()?->value() ?? 0;
    assertIntegration($familyBId > 0, 'E010 corrective current Family B was not persisted.');


    $staleFamilyRejected = false;
    try {
        $startWithFamily($familyAId, $periodIds['STALE_CURRENT_FAMILY']);
    } catch (EnrollmentFamilyContextUnavailable) {
        $staleFamilyRejected = true;
    }
    assertIntegration(
        $staleFamilyRejected
        && mariaDbEnrollmentCount($connectionA, $studentAId, $periodIds['STALE_CURRENT_FAMILY']) === 0,
        'E010 corrective locking read accepted stale Family A after Family B became active.'
    );

    $startWithFamily($familyBId, $periodIds['CURRENT_FAMILY']);
    $currentFamilyRow = $connectionA->prepare(
        'SELECT family_id FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $currentFamilyRow->execute([
        ':studentId' => $studentAId,
        ':periodId' => $periodIds['CURRENT_FAMILY'],
    ]);
    assertIntegration(
        (int) $currentFamilyRow->fetchColumn() === $familyBId,
        'E010 corrective Start did not accept the authoritative current Family B.'
    );


    $connectionA->beginTransaction();
    $familyRepositoryA->findActiveByStudentIdForUpdate(new FamilyStudentReference($studentBId));
    $rollbackBlocked = false;
    try {
        $connectionB->beginTransaction();
        $statement = $connectionB->prepare(
            'UPDATE family_students SET ended_at = :endedAt WHERE id = :id'
        );
        $statement->execute([
            ':endedAt' => '2026-08-21 18:03:00',
            ':id' => $studentBMembershipId,
        ]);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'E010 corrective rollback contention failed for a non-lock reason.',
                previous: $exception,
            );
        }
        $rollbackBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
    }
    $connectionA->rollBack();

    $connectionB->beginTransaction();
    $endStudentB = $connectionB->prepare(
        'UPDATE family_students SET ended_at = :endedAt WHERE id = :id AND ended_at IS NULL'
    );
    $endStudentB->execute([
        ':endedAt' => '2026-08-21 18:03:00',
        ':id' => $studentBMembershipId,
    ]);
    $connectionB->commit();
    assertIntegration(
        $rollbackBlocked
        && $endStudentB->rowCount() === 1
        && mariaDbEnrollmentCount($connectionA, $studentBId, $periodIds['ROLLBACK_RELEASE']) === 0,
        'E010 corrective rollback did not release membership lock or preserve zero partial Enrollment.'
    );
}

function mariaDbEnrollmentCount(PDO $connection, int $studentId, int $academicPeriodId): int
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $statement->execute([':studentId' => $studentId, ':periodId' => $academicPeriodId]);

    return (int) $statement->fetchColumn();
}

function runMariaDbRepresentativeEnrollmentPortalConcurrencyScenarioBody(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    int $representativePersonId,
    int $unrelatedPersonId,
    int $representativeId,
    int $studentId,
    int $expectedActivePeriodId,
    int $alternatePeriodId,
): void {
    $connectionA->exec('SET innodb_lock_wait_timeout = 1');
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $personsA = new PdoPersonRepository($managerA);
    $personsB = new PdoPersonRepository($managerB);
    $representativesA = new PdoRepresentativeRepository($managerA);
    $familiesA = new PdoFamilyRepository($managerA);
    $periodsA = new PdoAcademicPeriodRepository($managerA);
    $periodsB = new PdoAcademicPeriodRepository($managerB);
    $personId = new \App\Person\Domain\ValueObject\PersonId($representativePersonId);
    $otherPersonId = new \App\Person\Domain\ValueObject\PersonId($unrelatedPersonId);

    $connectionA->beginTransaction();
    $personal = $personsA->findByIdForUpdate($personId)
        ?? throw new RuntimeException('E011 Person lock fixture is unavailable.');
    $originalIdentification = $personal->identification();
    $originalSex = $personal->sexId();
    $originalStatus = $personal->status();
    $personal->updateIdentity(
        new PersonalName('E011 Personal', null, 'Serialized', null),
        $originalIdentification,
        $personal->birthDate(),
        $originalSex,
        $personal->maritalStatusId(),
        $personal->educationLevelId(),
        new DateTimeImmutable('2026-08-21', new DateTimeZone('UTC')),
    );
    $personsA->save($personal);
    $samePersonBlocked = false;
    try {
        $connectionB->beginTransaction();
        $personsB->findByIdForUpdate($personId);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E011 Person contention failed for a non-lock reason.', previous: $exception);
        }
        $samePersonBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
    }
    $connectionA->commit();

    $connectionB->beginTransaction();
    $contact = $personsB->findByIdForUpdate($personId)
        ?? throw new RuntimeException('E011 Person contact fixture disappeared.');
    $contact->updateContactInformation(new ContactInformation(
        'e011-contact@example.test',
        'portal mobile',
        'portal landline',
    ));
    $personsB->save($contact);
    $connectionB->commit();
    $finalPerson = $personsA->findById($personId);
    assertIntegration(
        $samePersonBlocked
        && $finalPerson?->personalName()->firstName() === 'E011 Personal'
        && $finalPerson->contactInformation()?->email() === 'e011-contact@example.test'
        && ($originalIdentification === null
            ? $finalPerson->identification() === null
            : $finalPerson->identification()?->equals($originalIdentification) === true)
        && $finalPerson->sexId() === $originalSex
        && $finalPerson->status() === $originalStatus,
        'E011 Person same-row serialization lost Personal Contact or restricted state.'
    );

    $connectionA->beginTransaction();
    $personsA->findByIdForUpdate($personId);
    $connectionB->beginTransaction();
    $unrelated = $personsB->findByIdForUpdate($otherPersonId);
    assertIntegration(
        $unrelated?->id()?->value() === $unrelatedPersonId,
        'E011 Person lock blocked an unrelated Person row.'
    );
    $connectionB->rollBack();
    $connectionA->rollBack();

    $representativeReference = new \App\Representative\Domain\ValueObject\RepresentativeId($representativeId);
    $connectionA->beginTransaction();
    $representative = $representativesA->findByIdForUpdate($representativeReference)
        ?? throw new RuntimeException('E011 Representative lock fixture is unavailable.');
    $representativeStatus = $representative->status();
    $representativePersonReference = $representative->personId()->value();
    $representative->replaceEmploymentInformation(new EmploymentInformation(
        'E011 occupation', null, null, null, null,
    ));
    $representativesA->save($representative);
    $representativeBlocked = false;
    try {
        $connectionB->beginTransaction();
        (new PdoRepresentativeRepository($managerB))->findByIdForUpdate($representativeReference);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E011 Representative contention failed for a non-lock reason.', previous: $exception);
        }
        $representativeBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
    }
    $connectionA->commit();
    $finalRepresentative = $representativesA->findById($representativeReference);
    assertIntegration(
        $representativeBlocked
        && $finalRepresentative?->employmentInformation()?->occupation() === 'E011 occupation'
        && $finalRepresentative->status() === $representativeStatus
        && $finalRepresentative->personId()->value() === $representativePersonReference,
        'E011 Representative employment serialization lost identity or status.'
    );

    $currentFamily = $familiesA->findActiveByStudentId(new FamilyStudentReference($studentId));
    $familyIdValue = $currentFamily?->id()?->value() ?? 0;
    assertIntegration($familyIdValue > 0, 'E011 active Family fixture is unavailable.');
    $familyId = new FamilyId($familyIdValue);
    $familyRepresentative = new FamilyRepresentativeReference($representativeId);
    $connectionA->beginTransaction();
    $lockedFamily = $familiesA->findActiveByRepresentativeAndFamilyForUpdate(
        $familyRepresentative,
        $familyId,
    );
    assertIntegration($lockedFamily?->id()?->value() === $familyIdValue, 'E011 FamilyRepresentative lock failed.');
    $representativeRevocationBlocked = false;
    try {
        $connectionB->beginTransaction();
        $statement = $connectionB->prepare(
            'UPDATE family_representatives SET ended_at = :endedAt '
            . 'WHERE family_id = :familyId AND representative_id = :representativeId AND ended_at IS NULL'
        );
        $statement->execute([
            ':endedAt' => '2026-08-21 19:00:00',
            ':familyId' => $familyIdValue,
            ':representativeId' => $representativeId,
        ]);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E011 FamilyRepresentative contention failed for a non-lock reason.', previous: $exception);
        }
        $representativeRevocationBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        $connectionA->rollBack();
    }
    $connectionB->beginTransaction();
    $revokeRepresentative = $connectionB->prepare(
        'UPDATE family_representatives SET ended_at = :endedAt '
        . 'WHERE family_id = :familyId AND representative_id = :representativeId AND ended_at IS NULL'
    );
    $revokeRepresentative->execute([
        ':endedAt' => '2026-08-21 19:00:00',
        ':familyId' => $familyIdValue,
        ':representativeId' => $representativeId,
    ]);
    $connectionB->commit();
    $connectionA->beginTransaction();
    $revokedRepresentativeRejected = $familiesA->findActiveByRepresentativeAndFamilyForUpdate(
        $familyRepresentative,
        $familyId,
    ) === null;
    $connectionA->rollBack();
    $restoreRepresentative = $connectionA->prepare(
        'UPDATE family_representatives SET ended_at = NULL '
        . 'WHERE family_id = :familyId AND representative_id = :representativeId '
        . 'AND ended_at = :endedAt'
    );
    $restoreRepresentative->execute([
        ':familyId' => $familyIdValue,
        ':representativeId' => $representativeId,
        ':endedAt' => '2026-08-21 19:00:00',
    ]);
    assertIntegration(
        $representativeRevocationBlocked && $revokedRepresentativeRejected && $restoreRepresentative->rowCount() === 1,
        'E011 FamilyRepresentative portal-first or revocation-first serialization failed.'
    );

    $studentReference = new FamilyStudentReference($studentId);
    $connectionA->beginTransaction();
    $familiesA->findActiveByStudentIdForUpdate($studentReference);
    $studentRevocationBlocked = false;
    try {
        $connectionB->beginTransaction();
        $statement = $connectionB->prepare(
            'UPDATE family_students SET ended_at = :endedAt WHERE active_student_id = :studentId'
        );
        $statement->execute([':endedAt' => '2026-08-21 19:01:00', ':studentId' => $studentId]);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E011 FamilyStudent contention failed for a non-lock reason.', previous: $exception);
        }
        $studentRevocationBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        $connectionA->rollBack();
    }
    $connectionB->beginTransaction();
    $revokeStudent = $connectionB->prepare(
        'UPDATE family_students SET ended_at = :endedAt WHERE active_student_id = :studentId'
    );
    $revokeStudent->execute([':endedAt' => '2026-08-21 19:01:00', ':studentId' => $studentId]);
    $connectionB->commit();
    $connectionA->beginTransaction();
    $revokedStudentRejected = $familiesA->findActiveByStudentIdForUpdate($studentReference) === null;
    $connectionA->rollBack();
    $restoreStudent = $connectionA->prepare(
        'UPDATE family_students SET ended_at = NULL WHERE student_id = :studentId AND ended_at = :endedAt'
    );
    $restoreStudent->execute([':studentId' => $studentId, ':endedAt' => '2026-08-21 19:01:00']);
    assertIntegration(
        $studentRevocationBlocked && $revokedStudentRejected && $restoreStudent->rowCount() === 1,
        'E011 FamilyStudent portal-first or revocation-first serialization failed.'
    );

    $connectionA->beginTransaction();
    $periodsA->lockActiveContextForRead();
    $connectionB->beginTransaction();
    $periodsB->lockActiveContextForRead();
    $secondSharedLockSucceeded = $connectionB->inTransaction();
    $connectionB->rollBack();
    $connectionA->rollBack();

    $connectionA->beginTransaction();
    $periodsA->lockActiveContextForRead();
    $periodSwitchBlocked = false;
    try {
        (new ActivateAcademicPeriod($periodsB, new PdoTransactionRunner($managerB)))->handle($alternatePeriodId);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E011 ActivePeriod contention failed for a non-lock reason.', previous: $exception);
        }
        $periodSwitchBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        $connectionA->rollBack();
    }
    (new ActivateAcademicPeriod($periodsB, new PdoTransactionRunner($managerB)))->handle($alternatePeriodId);
    $connectionA->beginTransaction();
    $periodsA->lockActiveContextForRead();
    $switchFirstRejectedAsStale = $periodsA->findActive()?->id()?->value() !== $expectedActivePeriodId;
    $connectionA->rollBack();
    (new ActivateAcademicPeriod($periodsA, new PdoTransactionRunner($managerA)))->handle($expectedActivePeriodId);
    assertIntegration(
        $secondSharedLockSucceeded
        && $periodSwitchBlocked
        && $switchFirstRejectedAsStale
        && $periodsA->findActive()?->id()?->value() === $expectedActivePeriodId,
        'E011 ActivePeriod shared stabilization or stale-page rejection failed.'
    );

    $connectionA->beginTransaction();
    $personsA->findByIdForUpdate($personId);
    $connectionA->rollBack();
    $connectionB->beginTransaction();
    $released = $personsB->findByIdForUpdate($personId);
    $connectionB->rollBack();
    assertIntegration(
        $released?->id()?->value() === $representativePersonId,
        'E011 rollback did not release portal root lock.'
    );
}

function runMariaDbRepresentativeEnrollmentPortalConcurrencyScenario(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    int $representativePersonId,
    int $unrelatedPersonId,
    int $representativeId,
    int $studentId,
    int $expectedActivePeriodId,
    int $alternatePeriodId,
): void {
    try {
        runMariaDbRepresentativeEnrollmentPortalConcurrencyScenarioBody(
            $managerA,
            $managerB,
            $connectionA,
            $connectionB,
            $representativePersonId,
            $unrelatedPersonId,
            $representativeId,
            $studentId,
            $expectedActivePeriodId,
            $alternatePeriodId,
        );
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }
}

function runMariaDbEnrollmentSubmissionApplicationScenario(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    SessionManager $session,
    int $representativeUserId,
    int $representativePersonId,
    int $representativeId,
    int $studentId,
    int $familyId,
    int $otherFamilyId,
    int $relationshipTypeId,
): void {
    $familiesA = new PdoFamilyRepository($managerA);
    $familiesB = new PdoFamilyRepository($managerB);
    $personsA = new PdoPersonRepository($managerA);
    $representativesA = new PdoRepresentativeRepository($managerA);
    $studentsA = new PdoStudentRepository($managerA);
    $periodsA = new PdoAcademicPeriodRepository($managerA);
    $requirementsA = new PdoAcknowledgementRequirementRepository($managerA);
    $completionsA = new PdoRepresentativeAcknowledgementCompletionRepository($managerA);
    $enrollmentsA = new PdoEnrollmentRepository($managerA);
    $enrollmentsB = new PdoEnrollmentRepository($managerB);
    $transactionsA = new PdoTransactionRunner($managerA);
    $transactionsB = new PdoTransactionRunner($managerB);

    $representativePerson = $personsA->findById(
        new \App\Person\Domain\ValueObject\PersonId($representativePersonId)
    ) ?? throw new RuntimeException('E012 Submission Representative Person fixture is unavailable.');
    $representativePerson->updateContactInformation(new ContactInformation(
        'submission-representative@example.test',
        'submission mobile',
        null,
    ));
    $personsA->save($representativePerson);

    $familyReference = new FamilyId($familyId);
    $family = $familiesA->findById($familyReference)
        ?? throw new RuntimeException('E012 Submission Family fixture is unavailable.');
    $representativeReference = new FamilyRepresentativeReference($representativeId);
    $hasRepresentative = false;
    foreach ($family->activeRepresentatives() as $membership) {
        $hasRepresentative = $hasRepresentative
            || $membership->representativeId()->equals($representativeReference);
    }
    if (!$hasRepresentative) {
        $family->addRepresentative(
            $representativeReference,
            new RelationshipTypeId($relationshipTypeId),
            new DateTimeImmutable('2026-08-22 08:00:00', new DateTimeZone('UTC')),
        );
        $family = $familiesA->save($family);
    }

    $studentReference = new FamilyStudentReference($studentId);
    $hasAddress = false;
    foreach ($family->studentAddressAssignments() as $assignment) {
        $hasAddress = $hasAddress || ($assignment->isActive() && $assignment->studentId()->equals($studentReference));
    }
    if (!$hasAddress) {
        $family->addAddress(
            new AddressLabel('Submission address'),
            new Address('Submission current street', null, null, null, null, null),
        );
        $family = $familiesA->save($family);
        $addressId = null;
        foreach ($family->activeAddresses() as $address) {
            if ($address->label()->value() === 'Submission address') {
                $addressId = $address->id();
            }
        }
        if ($addressId === null) {
            throw new RuntimeException('E012 Submission Address fixture was not generated.');
        }
        $family->assignAddressToStudent(
            $studentReference,
            $addressId,
            new DateTimeImmutable('2026-08-22 08:01:00', new DateTimeZone('UTC')),
        );
        $family = $familiesA->save($family);
    }

    $hasEmergency = false;
    foreach ($family->emergencyContactAssignments() as $assignment) {
        $hasEmergency = $hasEmergency
            || ($assignment->isActive() && $assignment->studentId()->equals($studentReference));
    }
    if (!$hasEmergency) {
        $family->addEmergencyContact(
            new FamilyResourceName('Submission emergency'),
            new RelationshipTypeId($relationshipTypeId),
            new EmergencyContactInformation('emergency mobile', null, null, null),
        );
        $family = $familiesA->save($family);
        $emergencyId = null;
        foreach ($family->activeEmergencyContacts() as $contact) {
            if ($contact->names()->value() === 'Submission emergency') {
                $emergencyId = $contact->id();
            }
        }
        if ($emergencyId === null) {
            throw new RuntimeException('E012 Submission Emergency Contact fixture was not generated.');
        }
        $family->assignEmergencyContactToStudent(
            $studentReference,
            $emergencyId,
            new EmergencyContactPriority(1),
            new DateTimeImmutable('2026-08-22 08:02:00', new DateTimeZone('UTC')),
        );
        $family = $familiesA->save($family);
    }

    $hasPickup = false;
    foreach ($family->authorizedPickupAssignments() as $assignment) {
        $hasPickup = $hasPickup
            || ($assignment->isActive() && $assignment->studentId()->equals($studentReference));
    }
    if (!$hasPickup) {
        $family->addAuthorizedPickup(
            new FamilyResourceName('Submission pickup'),
            new RelationshipTypeId($relationshipTypeId),
            new AuthorizedPickupInformation('pickup mobile', null, null),
            null,
        );
        $family = $familiesA->save($family);
        $pickupId = null;
        foreach ($family->activeAuthorizedPickups() as $pickup) {
            if ($pickup->names()->value() === 'Submission pickup') {
                $pickupId = $pickup->id();
            }
        }
        if ($pickupId === null) {
            throw new RuntimeException('E012 Submission Authorized Pickup fixture was not generated.');
        }
        $family->assignAuthorizedPickupToStudent(
            $studentReference,
            $pickupId,
            new DateTimeImmutable('2026-08-22 08:03:00', new DateTimeZone('UTC')),
        );
        $family = $familiesA->save($family);
    }

    $inactiveStatusId = (int) $connectionA->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    $insertPeriod = $connectionA->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    $insertPeriod->execute([
        ':code' => 'E012_SUBMIT_ACTIVE',
        ':name' => 'E012 Submission Active',
        ':startsOn' => '2026-08-01',
        ':endsOn' => '2027-07-31',
        ':statusId' => $inactiveStatusId,
    ]);
    $periodId = (int) $connectionA->lastInsertId();
    $insertPeriod->execute([
        ':code' => 'E012_SUBMIT_ALTERNATE',
        ':name' => 'E012 Submission Alternate',
        ':startsOn' => '2027-08-01',
        ':endsOn' => '2028-07-31',
        ':statusId' => $inactiveStatusId,
    ]);
    $alternatePeriodId = (int) $connectionA->lastInsertId();
    assertIntegration(
        $periodId > 0 && $alternatePeriodId > 0,
        'E012 Submission AcademicPeriod fixtures were not generated.'
    );
    (new ActivateAcademicPeriod($periodsA, $transactionsA))->handle($periodId);

    $gradeId = (int) $connectionA->query('SELECT id FROM grades ORDER BY id LIMIT 1')->fetchColumn();
    $identificationTypeId = (int) $connectionA->query(
        'SELECT id FROM document_types ORDER BY id LIMIT 1'
    )->fetchColumn();
    $draft = EnrollmentAggregate::startDraft(
        new EnrollmentStudentId($studentId),
        new EnrollmentFamilyId($familyId),
        new EnrollmentAcademicPeriodId($periodId),
        new DateTimeImmutable('2026-08-23 09:00:00', new DateTimeZone('UTC')),
        new EnrollmentAcademicPlacement(new EnrollmentGradeId($gradeId), null),
        new EnrollmentBillingInformation(
            new EnrollmentIdentificationTypeId($identificationTypeId),
            'E012-BILLING',
            'Submission Representative',
            'Submission billing address',
            'submission-billing@example.test',
            'billing phone',
        ),
        new EnrollmentMedicalInformation(
            false, null, false, null, false, null, false, null, false, null,
            null, null, null,
        ),
        new EnrollmentTransportInformation(false),
        false,
    );
    $persistedDraft = $enrollmentsA->save($draft);
    $enrollmentId = $persistedDraft->id()?->value() ?? 0;
    assertIntegration($enrollmentId > 0, 'E012 Submission Draft identity was not generated.');

    $session->regenerateForUser($representativeUserId);
    $familySession = new RepresentativeFamilyContextSession($session);
    $familySession->select($familyId);

    $resolveFor = static function (
        ConnectionManager $manager,
        SessionManager $session,
    ): ResolveFamilyContext {
        $representative = new GetAuthenticatedRepresentative(
            new GetAuthenticatedUser($session, new PdoUserRepository($manager)),
            new PdoRepresentativeRepository($manager),
        );

        return new ResolveFamilyContext(
            new GetAuthorizedFamilies($representative, new PdoFamilyRepository($manager)),
            new RepresentativeFamilyContextSession($session),
        );
    };
    $clock = static function (string $instant): Clock {
        return new class(new DateTimeImmutable($instant, new DateTimeZone('UTC'))) implements Clock {
            public int $calls = 0;

            public function __construct(private readonly DateTimeImmutable $instant)
            {
            }

            public function now(): DateTimeImmutable
            {
                ++$this->calls;

                return $this->instant;
            }
        };
    };
    $buildSubmit = static function (
        ConnectionManager $manager,
        ResolveFamilyContext $resolve,
        Clock $clock,
        ?EnrollmentRepository $enrollments = null,
    ): SubmitRepresentativeEnrollment {
        return new SubmitRepresentativeEnrollment(
            $resolve,
            new PdoAcademicPeriodRepository($manager),
            new CheckInstitutionalAcknowledgementSubmissionSatisfaction(
                new PdoAcknowledgementRequirementRepository($manager),
                new PdoRepresentativeAcknowledgementCompletionRepository($manager),
            ),
            new PdoFamilyRepository($manager),
            new PdoStudentRepository($manager),
            new PdoPersonRepository($manager),
            $enrollments ?? new PdoEnrollmentRepository($manager),
            new EnrollmentSubmissionValidator(),
            new PdoTransactionRunner($manager),
            $clock,
        );
    };
    $input = new SubmitRepresentativeEnrollmentInput($familyId, $periodId, $studentId);
    $firstClock = $clock('2026-08-23 10:11:12.987654');
    $first = $buildSubmit($managerA, $resolveFor($managerA, $session), $firstClock)->handle($input);
    assertIntegration(
        $first->id === $enrollmentId
        && $first->status === 'SUBMITTED'
        && $first->submittedAt?->format('Y-m-d H:i:s') === '2026-08-23 10:11:12'
        && $first->completedAt === null
        && $first->cancelledAt === null
        && $firstClock->calls === 1,
        'E012 first Submission did not persist the exact lifecycle transition.'
    );

    $requirement = (new CreateAcknowledgementRequirement(
        $requirementsA,
        $transactionsA,
    ))->handle(new CreateAcknowledgementRequirementInput(
        $periodId,
        'E012 current acknowledgement',
        '/e012/current-acknowledgement',
        null,
        'ACTIVE',
    ));
    $reopened = $enrollmentsA->findById(new EnrollmentAggregateId($enrollmentId))
        ?? throw new RuntimeException('E012 submitted Enrollment disappeared before Requirement-first test.');
    $reopened->reopen();
    $enrollmentsA->save($reopened);
    $blockedClock = $clock('2026-08-23 10:12:13');
    $requirementFirstRejected = false;
    try {
        $buildSubmit($managerA, $resolveFor($managerA, $session), $blockedClock)->handle($input);
    } catch (EnrollmentSubmissionNotReady $exception) {
        foreach ($exception->validation->requirements as $item) {
            $requirementFirstRejected = $requirementFirstRejected
                || ($item->code === 'ACKNOWLEDGEMENTS' && !$item->satisfied);
        }
    }
    assertIntegration(
        $requirementFirstRejected && $blockedClock->calls === 0,
        'E012 Requirement-first stable evaluation did not reject pending acknowledgements before Clock.'
    );
    (new CompleteRepresentativeAcknowledgements(
        $requirementsA,
        $completionsA,
        $transactionsA,
    ))->handle(new CompleteRepresentativeAcknowledgementsInput(
        $representativeId,
        $periodId,
        [$requirement->id],
        new DateTimeImmutable('2026-08-23 10:13:14', new DateTimeZone('UTC')),
    ));
    $resubmitClock = $clock('2026-08-23 10:14:15');
    $resubmitted = $buildSubmit($managerA, $resolveFor($managerA, $session), $resubmitClock)->handle($input);
    assertIntegration(
        $resubmitted->id === $enrollmentId
        && $resubmitted->submittedAt?->format('Y-m-d H:i:s') === '2026-08-23 10:14:15'
        && $resubmitClock->calls === 1,
        'E012 Resubmission did not preserve identity or replace SubmittedAt after Completion.'
    );

    $annualRejected = false;
    try {
        (new UpdateEnrollmentTransportInformation($enrollmentsA, $transactionsA))->handle(
            new UpdateEnrollmentTransportInformationInput(
                $enrollmentId,
                $studentId,
                $familyId,
                $periodId,
                true,
            )
        );
    } catch (InvalidEnrollmentState) {
        $annualRejected = true;
    }
    assertIntegration($annualRejected, 'E012 post-Submission annual mutation did not remain Draft-only.');

    $representativePerson = $personsA->findById(
        new \App\Person\Domain\ValueObject\PersonId($representativePersonId)
    ) ?? throw new RuntimeException('E012 live Contact fixture disappeared after Submission.');
    $representativePerson->updateContactInformation(new ContactInformation(
        'submission-updated@example.test',
        'submission updated mobile',
        null,
    ));
    $updatedRepresentativePerson = $personsA->save($representativePerson);
    $family = $familiesA->findById($familyReference)
        ?? throw new RuntimeException('E012 live Family fixture disappeared after Submission.');
    $addressToUpdate = null;
    foreach ($family->activeAddresses() as $address) {
        if ($address->label()->value() === 'Submission address') {
            $addressToUpdate = $address;
        }
    }
    if ($addressToUpdate !== null && $addressToUpdate->id() !== null) {
        $family->updateAddress(
            $addressToUpdate->id(),
            new AddressLabel('Submission address updated'),
            new Address('Submission updated street', null, null, null, null, null),
        );
        $family = $familiesA->save($family);
    }
    assertIntegration(
        $updatedRepresentativePerson->contactInformation()?->email() === 'submission-updated@example.test'
        && $family->id()?->value() === $familyId
        && $enrollmentsA->findById(new EnrollmentAggregateId($enrollmentId))?->status()
            === EnrollmentAggregateStatus::Submitted,
        'E012 live Contact or Family Resource mutation after Submission was not preserved independently.'
    );

    $reopened = $enrollmentsA->findById(new EnrollmentAggregateId($enrollmentId))
        ?? throw new RuntimeException('E012 Enrollment disappeared before autosave-first test.');
    $reopened->reopen();
    $enrollmentsA->save($reopened);
    (new UpdateEnrollmentTransportInformation($enrollmentsA, $transactionsA))->handle(
        new UpdateEnrollmentTransportInformationInput(
            $enrollmentId,
            $studentId,
            $familyId,
            $periodId,
            true,
        )
    );
    $autosaveFirst = $buildSubmit(
        $managerA,
        $resolveFor($managerA, $session),
        $clock('2026-08-23 10:15:16'),
    )->handle($input);
    assertIntegration(
        $autosaveFirst->transportInformation?->requiresInstitutionalTransport === true,
        'E012 annual autosave-first state was not observed by Submission.'
    );

    $reopened = $enrollmentsA->findById(new EnrollmentAggregateId($enrollmentId))
        ?? throw new RuntimeException('E012 Enrollment disappeared before rollback test.');
    $reopened->reopen();
    $enrollmentsA->save($reopened);
    $rollbackFailure = new RuntimeException('simulated E012 post-transition persistence failure');
    $failingEnrollments = new class($enrollmentsA, $rollbackFailure) implements EnrollmentRepository {
        public function __construct(
            private readonly EnrollmentRepository $delegate,
            private readonly RuntimeException $failure,
        ) {
        }

        public function findById(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            return $this->delegate->findById($id);
        }

        public function findByIdForUpdate(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            return $this->delegate->findByIdForUpdate($id);
        }

        public function findByStudentAndAcademicPeriod(
            EnrollmentStudentId $studentId,
            EnrollmentAcademicPeriodId $academicPeriodId,
        ): ?EnrollmentAggregate {
            return $this->delegate->findByStudentAndAcademicPeriod($studentId, $academicPeriodId);
        }

        public function save(EnrollmentAggregate $enrollment): EnrollmentAggregate
        {
            $this->delegate->save($enrollment);
            throw $this->failure;
        }
    };
    $caughtRollbackFailure = null;
    try {
        $buildSubmit(
            $managerA,
            $resolveFor($managerA, $session),
            $clock('2026-08-23 10:16:17'),
            $failingEnrollments,
        )->handle($input);
    } catch (Throwable $exception) {
        $caughtRollbackFailure = $exception;
    }
    assertIntegration(
        $caughtRollbackFailure === $rollbackFailure
        && $enrollmentsA->findById(new EnrollmentAggregateId($enrollmentId))?->status()
            === EnrollmentAggregateStatus::Draft
        && !$connectionA->inTransaction(),
        'E012 rollback after Domain transition did not restore exact Draft state.'
    );

    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $competingClock = $clock('2026-08-23 10:16:18');
    $competingSubmit = $buildSubmit(
        $managerB,
        $resolveFor($managerB, $session),
        $competingClock,
        $enrollmentsB,
    );
    $competingBlocked = false;
    $afterEnrollmentLock = function () use (
        &$competingBlocked,
        $competingSubmit,
        $input,
    ): void {
        try {
            $competingSubmit->handle($input);
        } catch (PDOException $exception) {
            if (!isExpectedMariaDbLockException($exception)) {
                throw new RuntimeException(
                    'E012 concurrent duplicate Submission failed unexpectedly.',
                    previous: $exception,
                );
            }
            $competingBlocked = true;
        }
    };
    $interceptingEnrollments = new class($enrollmentsA, $afterEnrollmentLock) implements EnrollmentRepository {
        private bool $called = false;

        public function __construct(
            private readonly EnrollmentRepository $delegate,
            private readonly Closure $afterLock,
        ) {
        }

        public function findById(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            return $this->delegate->findById($id);
        }

        public function findByIdForUpdate(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            $enrollment = $this->delegate->findByIdForUpdate($id);
            if (!$this->called) {
                $this->called = true;
                ($this->afterLock)();
            }

            return $enrollment;
        }

        public function findByStudentAndAcademicPeriod(
            EnrollmentStudentId $studentId,
            EnrollmentAcademicPeriodId $academicPeriodId,
        ): ?EnrollmentAggregate {
            return $this->delegate->findByStudentAndAcademicPeriod($studentId, $academicPeriodId);
        }

        public function save(EnrollmentAggregate $enrollment): EnrollmentAggregate
        {
            return $this->delegate->save($enrollment);
        }
    };
    $winningClock = $clock('2026-08-23 10:16:19');
    $winningSubmission = $buildSubmit(
        $managerA,
        $resolveFor($managerA, $session),
        $winningClock,
        $interceptingEnrollments,
    )->handle($input);
    $duplicateRejectedAfterCommit = false;
    try {
        $competingSubmit->handle($input);
    } catch (EnrollmentSubmissionNotReady) {
        $duplicateRejectedAfterCommit = true;
    }
    assertIntegration(
        $competingBlocked
        && $winningSubmission->status === 'SUBMITTED'
        && $winningClock->calls === 1
        && $competingClock->calls === 0
        && $duplicateRejectedAfterCommit,
        'E012 concurrent duplicate Submission did not serialize to one transition and one rejection.'
    );

    $connectionA->beginTransaction();
    $familiesA->findByIdForUpdate($familyReference);
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $sameFamilyBlocked = false;
    try {
        $connectionB->beginTransaction();
        $familiesB->findByIdForUpdate($familyReference);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E012 same-Family root contention failed unexpectedly.', previous: $exception);
        }
        $sameFamilyBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        $connectionA->rollBack();
    }
    $connectionA->beginTransaction();
    $familiesA->findByIdForUpdate($familyReference);
    $connectionB->beginTransaction();
    $otherFamily = $familiesB->findByIdForUpdate(new FamilyId($otherFamilyId));
    $connectionB->rollBack();
    $connectionA->rollBack();
    assertIntegration(
        $sameFamilyBlocked && $otherFamily?->id()?->value() === $otherFamilyId,
        'E012 Family root lock either failed same-Family serialization or caused global blocking.'
    );

    $connectionA->beginTransaction();
    $requirementsA->lockConfigurationScopeForRead(new AcknowledgementAcademicPeriodId($periodId));
    $connectionB->beginTransaction();
    (new PdoAcknowledgementRequirementRepository($managerB))->lockConfigurationScopeForRead(
        new AcknowledgementAcademicPeriodId($periodId)
    );
    $connectionB->rollBack();
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $ackWriterBlocked = false;
    try {
        $connectionB->beginTransaction();
        (new PdoAcknowledgementRequirementRepository($managerB))->lockConfigurationScope(
            new AcknowledgementAcademicPeriodId($periodId)
        );
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException('E012 acknowledgement writer contention failed unexpectedly.', previous: $exception);
        }
        $ackWriterBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        $connectionA->rollBack();
    }
    assertIntegration($ackWriterBlocked, 'E012 shared acknowledgement lock did not exclude configuration writer.');

    (new ActivateAcademicPeriod($periodsA, $transactionsA))->handle($alternatePeriodId);
    $staleClock = $clock('2026-08-23 10:17:18');
    $stalePeriodRejected = false;
    try {
        $buildSubmit($managerA, $resolveFor($managerA, $session), $staleClock)->handle($input);
    } catch (EnrollmentSubmissionContextUnavailable) {
        $stalePeriodRejected = true;
    }
    assertIntegration(
        $stalePeriodRejected && $staleClock->calls === 0,
        'E012 period-switch-first did not reject the stale expected AcademicPeriod.'
    );
    (new ActivateAcademicPeriod($periodsA, $transactionsA))->handle($periodId);

    $connectionB->beginTransaction();
    $releasedEnrollment = $enrollmentsB->findByIdForUpdate(new EnrollmentAggregateId($enrollmentId));
    $connectionB->rollBack();
    assertIntegration(
        $releasedEnrollment?->id()?->value() === $enrollmentId,
        'E012 rollback did not release the Enrollment root lock.'
    );
}

function runMariaDbEnrollmentAdministrativeLifecycleScenario(
    ConnectionManager $managerA,
    ConnectionManager $managerB,
    PDO $connectionA,
    PDO $connectionB,
    int $studentAId,
    int $studentBId,
    int $familyId,
): void {
    $repositoryA = new PdoEnrollmentRepository($managerA);
    $repositoryB = new PdoEnrollmentRepository($managerB);
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');

    $gradeId = (int) $connectionA->query(
        "SELECT id FROM grades WHERE code = 'E010_GRADE'"
    )->fetchColumn();
    $sectionId = (int) $connectionA->query(
        "SELECT id FROM sections WHERE code = 'E010_SECTION'"
    )->fetchColumn();
    $inactiveStatusId = (int) $connectionA->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    assertIntegration(
        $gradeId > 0 && $sectionId > 0 && $inactiveStatusId > 0,
        'E012 Administrative lifecycle AcademicPlacement fixture is unavailable.'
    );

    $periodIds = [];
    for ($index = 1; $index <= 9; $index++) {
        $connectionA->prepare(
            'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
            . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
        )->execute([
            ':code' => sprintf('E012_ADMIN_%02d', $index),
            ':name' => sprintf('E012 Administrative %02d', $index),
            ':startsOn' => sprintf('2027-%02d-01', $index),
            ':endsOn' => sprintf('2027-%02d-28', $index),
            ':statusId' => $inactiveStatusId,
        ]);
        $periodId = (int) $connectionA->lastInsertId();
        assertIntegration($periodId > 0, 'E012 Administrative AcademicPeriod identity was not generated.');
        $periodIds[] = $periodId;
    }

    $annualBilling = new EnrollmentBillingInformation(
        new EnrollmentIdentificationTypeId(1),
        'E012-ADMIN-BILLING',
        'Administrative annual legal name',
        'Administrative annual address',
        'administrative-annual@example.test',
        'Administrative annual phone',
    );
    $annualMedical = new EnrollmentMedicalInformation(
        false,
        null,
        false,
        null,
        false,
        null,
        false,
        null,
        false,
        null,
        'Administrative pediatrician',
        '+593990001234',
        'Administrative annual observations',
    );
    $annualTransport = new EnrollmentTransportInformation(true);
    $submittedAt = new DateTimeImmutable('2026-08-24 15:01:02+00:00');

    $createEnrollment = static function (
        PdoEnrollmentRepository $repository,
        int $studentId,
        int $familyId,
        int $periodId,
        bool $submitted,
    ) use (
        $gradeId,
        $sectionId,
        $annualBilling,
        $annualMedical,
        $annualTransport,
        $submittedAt,
    ): EnrollmentAggregate {
        $enrollment = EnrollmentAggregate::startDraft(
            new EnrollmentStudentId($studentId),
            new EnrollmentFamilyId($familyId),
            new EnrollmentAcademicPeriodId($periodId),
            new DateTimeImmutable('2026-08-24 14:00:00+00:00'),
            new EnrollmentAcademicPlacement(
                new EnrollmentGradeId($gradeId),
                new EnrollmentSectionId($sectionId),
            ),
            $annualBilling,
            $annualMedical,
            $annualTransport,
            true,
        );
        if ($submitted) {
            $enrollment->submit($submittedAt);
        }

        return $repository->save($enrollment);
    };

    $clock = static fn (string $instant): Clock => new class($instant) implements Clock {
        public function __construct(private readonly string $instant)
        {
        }

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->instant);
        }
    };

    $execute = static function (
        string $operation,
        EnrollmentRepository $repository,
        ConnectionManager $manager,
        int $enrollmentId,
        string $instant,
    ) use ($clock): \App\Enrollment\Application\Dto\EnrollmentOutput {
        $transactions = new PdoTransactionRunner($manager);

        return match ($operation) {
            'reopen' => (new ReopenEnrollment($repository, $transactions))->handle($enrollmentId),
            'complete' => (new CompleteEnrollment(
                $repository,
                $transactions,
                $clock($instant),
            ))->handle($enrollmentId),
            'cancel' => (new CancelEnrollment(
                $repository,
                $transactions,
                $clock($instant),
            ))->handle($enrollmentId),
            default => throw new RuntimeException('Unsupported E012 Administrative lifecycle operation.'),
        };
    };

    $matrix = [
        ['Reopen/Reopen', 'reopen', 'reopen', true, 'DRAFT', 'DRAFT', false],
        ['Complete/Complete', 'complete', 'complete', true, 'COMPLETED', 'COMPLETED', false],
        ['Cancel/Cancel', 'cancel', 'cancel', false, 'CANCELLED', 'CANCELLED', false],
        ['Reopen/Complete', 'reopen', 'complete', true, 'DRAFT', 'DRAFT', false],
        ['Reopen/Cancel', 'reopen', 'cancel', true, 'DRAFT', 'CANCELLED', true],
        ['Complete/Cancel', 'complete', 'cancel', true, 'COMPLETED', 'COMPLETED', false],
    ];

    foreach ($matrix as $index => [
        $label,
        $firstOperation,
        $secondOperation,
        $startsSubmitted,
        $expectedWinnerStatus,
        $expectedFinalStatus,
        $secondAllowed,
    ]) {
        $persisted = $createEnrollment(
            $repositoryA,
            $studentAId,
            $familyId,
            $periodIds[$index],
            $startsSubmitted,
        );
        $enrollmentId = $persisted->id()
            ?? throw new RuntimeException('E012 Administrative race Enrollment identity is unavailable.');
        $before = (new GetAdministrativeEnrollmentReview($repositoryA))->handle($enrollmentId->value());
        $competingBlocked = false;
        $afterLock = static function () use (
            &$competingBlocked,
            $execute,
            $secondOperation,
            $repositoryB,
            $managerB,
            $enrollmentId,
            $index,
            $label,
        ): void {
            try {
                $execute(
                    $secondOperation,
                    $repositoryB,
                    $managerB,
                    $enrollmentId->value(),
                    sprintf('2026-08-24 16:%02d:02+00:00', $index),
                );
            } catch (PDOException $exception) {
                if (!isExpectedMariaDbLockException($exception)) {
                    throw new RuntimeException(
                        sprintf('E012 Administrative %s competing operation failed unexpectedly.', $label),
                        previous: $exception,
                    );
                }
                $competingBlocked = true;
            }
        };
        $interceptingRepository = new class($repositoryA, $afterLock) implements EnrollmentRepository {
            private bool $intercepted = false;

            public function __construct(
                private readonly EnrollmentRepository $delegate,
                private readonly Closure $afterLock,
            ) {
            }

            public function findById(EnrollmentAggregateId $id): ?EnrollmentAggregate
            {
                return $this->delegate->findById($id);
            }

            public function findByIdForUpdate(EnrollmentAggregateId $id): ?EnrollmentAggregate
            {
                $enrollment = $this->delegate->findByIdForUpdate($id);
                if (!$this->intercepted) {
                    $this->intercepted = true;
                    ($this->afterLock)();
                }

                return $enrollment;
            }

            public function findByStudentAndAcademicPeriod(
                EnrollmentStudentId $studentId,
                EnrollmentAcademicPeriodId $academicPeriodId,
            ): ?EnrollmentAggregate {
                return $this->delegate->findByStudentAndAcademicPeriod($studentId, $academicPeriodId);
            }

            public function save(EnrollmentAggregate $enrollment): EnrollmentAggregate
            {
                return $this->delegate->save($enrollment);
            }
        };

        $winner = $execute(
            $firstOperation,
            $interceptingRepository,
            $managerA,
            $enrollmentId->value(),
            sprintf('2026-08-24 16:%02d:01+00:00', $index),
        );
        $loserRejectedAfterCommit = false;
        $secondAppliedAfterCommit = false;
        try {
            $execute(
                $secondOperation,
                $repositoryB,
                $managerB,
                $enrollmentId->value(),
                sprintf('2026-08-24 16:%02d:03+00:00', $index),
            );
            $secondAppliedAfterCommit = true;
        } catch (AdministrativeEnrollmentInvalidTransition) {
            $loserRejectedAfterCommit = true;
        }
        $actual = (new GetAdministrativeEnrollmentReview($repositoryB))->handle($enrollmentId->value());
        $expectedLifecycleSecond = $secondAllowed ? 3 : 1;

        assertIntegration(
            $competingBlocked
            && ($secondAllowed
                ? ($secondAppliedAfterCommit && !$loserRejectedAfterCommit)
                : (!$secondAppliedAfterCommit && $loserRejectedAfterCommit))
            && $winner->status === $expectedWinnerStatus
            && $actual->status === $expectedFinalStatus
            && $actual->id === $before->id
            && $actual->studentId === $before->studentId
            && $actual->familyId === $before->familyId
            && $actual->academicPeriodId === $before->academicPeriodId
            && $actual->startedAt == $before->startedAt
            && $actual->submittedAt == $before->submittedAt
            && $actual->academicPlacement == $before->academicPlacement
            && $actual->billingInformation == $before->billingInformation
            && $actual->medicalInformation == $before->medicalInformation
            && $actual->transportInformation == $before->transportInformation
            && $actual->isAuthorizedToLeaveAlone === $before->isAuthorizedToLeaveAlone
            && ($expectedFinalStatus !== 'COMPLETED'
                || ($actual->completedAt?->format('Y-m-d H:i:s') === sprintf(
                    '2026-08-24 16:%02d:%02d',
                    $index,
                    $expectedLifecycleSecond,
                )
                    && $actual->cancelledAt === null))
            && ($expectedFinalStatus !== 'CANCELLED'
                || ($actual->cancelledAt?->format('Y-m-d H:i:s') === sprintf(
                    '2026-08-24 16:%02d:%02d',
                    $index,
                    $expectedLifecycleSecond,
                )
                    && $actual->completedAt === null))
            && ($expectedFinalStatus !== 'DRAFT'
                || ($actual->completedAt === null && $actual->cancelledAt === null)),
            sprintf('E012 Administrative %s did not serialize to one coherent lifecycle transition.', $label),
        );
    }

    $lockedRoot = $createEnrollment($repositoryA, $studentAId, $familyId, $periodIds[6], true);
    $unrelatedRoot = $createEnrollment($repositoryA, $studentBId, $familyId, $periodIds[7], true);
    $lockedRootId = $lockedRoot->id()
        ?? throw new RuntimeException('E012 Administrative locked-root identity is unavailable.');
    $unrelatedRootId = $unrelatedRoot->id()
        ?? throw new RuntimeException('E012 Administrative unrelated-root identity is unavailable.');
    $connectionA->beginTransaction();
    $repositoryA->findByIdForUpdate($lockedRootId);
    $unrelatedResult = $execute(
        'complete',
        $repositoryB,
        $managerB,
        $unrelatedRootId->value(),
        '2026-08-24 17:01:02+00:00',
    );
    $connectionA->rollBack();
    assertIntegration(
        $unrelatedResult->status === 'COMPLETED'
        && $unrelatedResult->completedAt?->format('Y-m-d H:i:s') === '2026-08-24 17:01:02',
        'E012 Administrative lock on one Enrollment blocked an unrelated Enrollment.'
    );

    $rollbackRoot = $createEnrollment($repositoryA, $studentAId, $familyId, $periodIds[8], true);
    $rollbackRootId = $rollbackRoot->id()
        ?? throw new RuntimeException('E012 Administrative rollback-root identity is unavailable.');
    $failingRepository = new class($repositoryA) implements EnrollmentRepository {
        public function __construct(private readonly EnrollmentRepository $delegate)
        {
        }

        public function findById(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            return $this->delegate->findById($id);
        }

        public function findByIdForUpdate(EnrollmentAggregateId $id): ?EnrollmentAggregate
        {
            return $this->delegate->findByIdForUpdate($id);
        }

        public function findByStudentAndAcademicPeriod(
            EnrollmentStudentId $studentId,
            EnrollmentAcademicPeriodId $academicPeriodId,
        ): ?EnrollmentAggregate {
            return $this->delegate->findByStudentAndAcademicPeriod($studentId, $academicPeriodId);
        }

        public function save(EnrollmentAggregate $enrollment): EnrollmentAggregate
        {
            $this->delegate->save($enrollment);
            throw new RuntimeException('simulated E012 Administrative post-save failure');
        }
    };
    $rollbackObserved = false;
    try {
        $execute(
            'reopen',
            $failingRepository,
            $managerA,
            $rollbackRootId->value(),
            '2026-08-24 17:11:12+00:00',
        );
    } catch (RuntimeException $exception) {
        $rollbackObserved = $exception->getMessage() === 'simulated E012 Administrative post-save failure';
    }
    $afterRollback = (new GetAdministrativeEnrollmentReview($repositoryB))->handle($rollbackRootId->value());
    $afterRelease = $execute(
        'reopen',
        $repositoryB,
        $managerB,
        $rollbackRootId->value(),
        '2026-08-24 17:11:13+00:00',
    );
    assertIntegration(
        $rollbackObserved
        && $afterRollback->status === 'SUBMITTED'
        && $afterRollback->completedAt === null
        && $afterRollback->cancelledAt === null
        && $afterRelease->status === 'DRAFT',
        'E012 Administrative rollback left a partial transition or retained the root lock.'
    );

    $draftBilling = new EnrollmentBillingInformation(
        new EnrollmentIdentificationTypeId(1),
        'E012-ADMIN-UPDATED',
        'Updated after reopen',
        'Updated annual address',
        'updated-after-reopen@example.test',
        'Updated annual phone',
    );
    $connectionA->beginTransaction();
    $reopened = $repositoryA->findByIdForUpdate($rollbackRootId);
    $reopened?->updateBillingInformation($draftBilling);
    if ($reopened !== null) {
        $repositoryA->save($reopened);
    }
    $connectionA->commit();
    $reopenedResult = (new GetAdministrativeEnrollmentReview($repositoryA))->handle($rollbackRootId->value());
    $countStatement = $connectionA->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $countStatement->execute([':studentId' => $studentAId, ':periodId' => $periodIds[8]]);
    assertIntegration(
        $reopenedResult->status === 'DRAFT'
        && $reopenedResult->submittedAt?->format('Y-m-d H:i:s') === $submittedAt->format('Y-m-d H:i:s')
        && $reopenedResult->billingInformation?->legalName === 'Updated after reopen'
        && (int) $countStatement->fetchColumn() === 1,
        'E012 Administrative Reopen did not preserve one mutable Draft with Submission history.'
    );
}

function runMariaDbSubmittedEnrollmentQueryScenario(
    ConnectionManager $manager,
    PDO $connection,
    int $studentAId,
    int $studentBId,
    int $familyId,
): void {
    $repository = new PdoEnrollmentRepository($manager);
    $query = new PdoSubmittedEnrollmentIdQuery($manager);
    $inactiveStatusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    assertIntegration($inactiveStatusId > 0, 'E012 Delivery INACTIVE AcademicPeriod status is unavailable.');

    $activePeriods = $connection->query(
        "SELECT ap.id FROM academic_periods ap "
        . "INNER JOIN statuses s ON s.id = ap.status_id "
        . "INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE' ORDER BY ap.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        count($activePeriods) === 1 && (int) $activePeriods[0] > 0,
        'E012 Delivery requires exactly one current ACTIVE AcademicPeriod fixture.'
    );
    $activePeriodId = (int) $activePeriods[0];

    $activePairStatement = $connection->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE student_id = :studentId AND academic_period_id = :periodId'
    );
    $activeStudentId = null;
    foreach ([$studentBId, $studentAId] as $candidateStudentId) {
        $activePairStatement->execute([':studentId' => $candidateStudentId, ':periodId' => $activePeriodId]);
        if ((int) $activePairStatement->fetchColumn() === 0) {
            $activeStudentId = $candidateStudentId;
            break;
        }
    }
    assertIntegration(
        $activeStudentId !== null,
        'E012 Delivery active-period Submitted query fixture collides with an existing Enrollment.'
    );

    $periodIds = [];
    foreach (['DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'] as $index => $state) {
        $connection->prepare(
            'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
            . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
        )->execute([
            ':code' => 'E012_DELIVERY_' . $state,
            ':name' => 'E012 Delivery ' . $state,
            ':startsOn' => sprintf('2030-%02d-01', $index + 1),
            ':endsOn' => sprintf('2030-%02d-28', $index + 1),
            ':statusId' => $inactiveStatusId,
        ]);
        $periodIds[$state] = (int) $connection->lastInsertId();
        assertIntegration(
            $periodIds[$state] > 0,
            'E012 Delivery AcademicPeriod identity was not generated.'
        );
    }

    $create = static function (
        PdoEnrollmentRepository $enrollments,
        int $studentId,
        int $familyId,
        int $periodId,
        string $state,
        string $submittedAt,
    ): EnrollmentAggregate {
        $enrollment = EnrollmentAggregate::startDraft(
            new EnrollmentStudentId($studentId),
            new EnrollmentFamilyId($familyId),
            new EnrollmentAcademicPeriodId($periodId),
            new DateTimeImmutable('2029-12-01 00:00:00+00:00'),
        );
        if ($state !== 'DRAFT') {
            $enrollment->submit(new DateTimeImmutable($submittedAt));
        }
        if ($state === 'COMPLETED') {
            $enrollment->complete(new DateTimeImmutable('2030-01-20 12:00:00+00:00'));
        } elseif ($state === 'CANCELLED') {
            $enrollment->cancel(new DateTimeImmutable('2030-01-20 13:00:00+00:00'));
        }

        return $enrollments->save($enrollment);
    };

    $draft = $create($repository, $studentAId, $familyId, $periodIds['DRAFT'], 'DRAFT', '2030-01-01 00:00:00+00:00');
    $inactiveSubmitted = $create(
        $repository,
        $studentAId,
        $familyId,
        $periodIds['SUBMITTED'],
        'SUBMITTED',
        '2030-01-15 10:00:00+00:00',
    );
    $activeSubmitted = $create(
        $repository,
        $activeStudentId,
        $familyId,
        $activePeriodId,
        'SUBMITTED',
        '2030-01-15 11:00:00+00:00',
    );
    $completed = $create(
        $repository,
        $studentAId,
        $familyId,
        $periodIds['COMPLETED'],
        'COMPLETED',
        '2030-01-15 12:00:00+00:00',
    );
    $cancelled = $create(
        $repository,
        $studentAId,
        $familyId,
        $periodIds['CANCELLED'],
        'CANCELLED',
        '2030-01-15 13:00:00+00:00',
    );

    $activeSubmittedId = $activeSubmitted->id()?->value() ?? 0;
    $inactiveSubmittedId = $inactiveSubmitted->id()?->value() ?? 0;
    $excludedIds = array_map(
        static fn (EnrollmentAggregate $enrollment): int => $enrollment->id()?->value() ?? 0,
        [$draft, $completed, $cancelled],
    );
    $before = (int) $connection->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();
    $submittedIds = $query->findSubmittedEnrollmentIds();
    $after = (int) $connection->query('SELECT COUNT(*) FROM enrollments')->fetchColumn();

    $statusStatement = $connection->prepare(
        'SELECT st.code AS status_type_code, s.code AS status_code, ap_status.code AS period_status '
        . 'FROM enrollments e '
        . 'INNER JOIN statuses s ON s.id = e.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'INNER JOIN academic_periods ap ON ap.id = e.academic_period_id '
        . 'INNER JOIN statuses ap_status ON ap_status.id = ap.status_id '
        . 'WHERE e.id = :id'
    );
    $resolved = [];
    foreach ([$activeSubmittedId, $inactiveSubmittedId] as $id) {
        $statusStatement->execute([':id' => $id]);
        $resolved[$id] = $statusStatement->fetch(PDO::FETCH_ASSOC);
    }

    assertIntegration(
        $activeSubmittedId > 0
        && $inactiveSubmittedId > 0
        && array_slice($submittedIds, 0, 2) === [$activeSubmittedId, $inactiveSubmittedId]
        && count(array_intersect($submittedIds, $excludedIds)) === 0
        && $before === $after
        && $resolved[$activeSubmittedId]['status_type_code'] === 'ENROLLMENT_STATUS'
        && $resolved[$activeSubmittedId]['status_code'] === 'SUBMITTED'
        && $resolved[$activeSubmittedId]['period_status'] === 'ACTIVE'
        && $resolved[$inactiveSubmittedId]['status_type_code'] === 'ENROLLMENT_STATUS'
        && $resolved[$inactiveSubmittedId]['status_code'] === 'SUBMITTED'
        && $resolved[$inactiveSubmittedId]['period_status'] === 'INACTIVE',
        'E012 Delivery Submitted query did not preserve exact state filtering ordering and period context. '
        . json_encode([
            'submitted_ids' => $submittedIds,
            'expected_first_ids' => [$activeSubmittedId, $inactiveSubmittedId],
            'excluded_ids' => $excludedIds,
            'row_counts' => [$before, $after],
            'resolved_statuses' => $resolved,
        ], JSON_THROW_ON_ERROR)
    );
}

function runMariaDbEnrollmentReportingScenario(ConnectionManager $manager, PDO $connection): void
{
    $statusId = static function (string $type, string $code) use ($connection): int {
        $statement = $connection->prepare(
            'SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :type AND s.code = :code'
        );
        $statement->execute([':type' => $type, ':code' => $code]);
        $id = (int) $statement->fetchColumn();
        assertIntegration($id > 0, "E013 reporting fixture status {$type}/{$code} is unavailable.");

        return $id;
    };
    $generalActive = $statusId('GENERAL_STATUS', 'ACTIVE');
    $generalInactive = $statusId('GENERAL_STATUS', 'INACTIVE');
    $enrollmentStatuses = [];
    foreach (['DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'] as $code) {
        $enrollmentStatuses[$code] = $statusId('ENROLLMENT_STATUS', $code);
    }

    $firstId = static function (string $table) use ($connection): int {
        $id = (int) $connection->query("SELECT id FROM {$table} ORDER BY id ASC LIMIT 1")->fetchColumn();
        assertIntegration($id > 0, "E013 reporting fixture requires {$table}.");

        return $id;
    };
    $documentTypeId = $firstId('document_types');
    $sexId = $firstId('sexes');
    $relationshipTypeId = $firstId('relationship_types');
    $gradeIds = array_map(
        'intval',
        $connection->query('SELECT id FROM grades ORDER BY sort_order ASC, id ASC LIMIT 2')
            ->fetchAll(PDO::FETCH_COLUMN),
    );
    assertIntegration(count($gradeIds) === 2, 'E013 reporting fixture requires two Grade references.');
    $gradeOneId = $gradeIds[0];
    $gradeTwoId = $gradeIds[1];
    $sectionOneId = $firstId('sections');

    $connection->prepare('UPDATE academic_periods SET status_id = :inactive WHERE status_id = :active')
        ->execute([':inactive' => $generalInactive, ':active' => $generalActive]);
    $insertPeriod = $connection->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    $insertPeriod->execute([
        ':code' => 'E013_P2_HIST', ':name' => 'E013 Reporting Historical',
        ':startsOn' => '2040-09-01', ':endsOn' => '2041-06-30', ':statusId' => $generalInactive,
    ]);
    $historicalPeriodId = (int) $connection->lastInsertId();
    $insertPeriod->execute([
        ':code' => 'E013_P2_ACTIVE', ':name' => 'E013 Reporting Active',
        ':startsOn' => '2041-09-01', ':endsOn' => '2042-06-30', ':statusId' => $generalActive,
    ]);
    $activePeriodId = (int) $connection->lastInsertId();
    assertIntegration($historicalPeriodId > 0 && $activePeriodId > 0, 'E013 AcademicPeriod identities were not generated.');

    $insertPerson = $connection->prepare(
        'INSERT INTO persons (first_name, first_surname, document_type_id, document_number, birth_date, '
        . 'sex_id, email, mobile_phone, landline_phone, status_id) VALUES '
        . '(:firstName, :surname, :documentTypeId, :documentNumber, :birthDate, :sexId, '
        . ':email, :mobilePhone, :landlinePhone, :statusId)'
    );
    $studentIds = [];
    for ($index = 1; $index <= 6; $index++) {
        $insertPerson->execute([
            ':firstName' => 'E013Student' . $index,
            ':surname' => sprintf('E013Surname%02d', $index),
            ':documentTypeId' => $documentTypeId,
            ':documentNumber' => 'E013-STUDENT-' . $index,
            ':birthDate' => '2015-01-0' . $index,
            ':sexId' => $sexId,
            ':email' => null,
            ':mobilePhone' => null,
            ':landlinePhone' => null,
            ':statusId' => $generalActive,
        ]);
        $personId = (int) $connection->lastInsertId();
        $studentStatement = $connection->prepare(
            'INSERT INTO students (person_id, institutional_code, admission_date, status_id) '
            . 'VALUES (:personId, :code, :admissionDate, :statusId)'
        );
        $studentStatement->execute([
            ':personId' => $personId,
            ':code' => 'E013-P2-STUDENT-' . $index,
            ':admissionDate' => '2040-09-01',
            ':statusId' => $index === 6 ? $generalInactive : $generalActive,
        ]);
        $studentIds[$index] = (int) $connection->lastInsertId();
    }

    $insertPerson->execute([
        ':firstName' => 'E013Current', ':surname' => 'Representative',
        ':documentTypeId' => $documentTypeId, ':documentNumber' => 'E013-REPRESENTATIVE',
        ':birthDate' => '1980-01-01', ':sexId' => $sexId,
        ':email' => 'e013.personal@example.test', ':mobilePhone' => '0991300000',
        ':landlinePhone' => '022130000', ':statusId' => $generalActive,
    ]);
    $representativePersonId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO representatives (person_id, occupation, company, position, work_phone, work_email, status_id) '
        . 'VALUES (:personId, :occupation, :company, :position, :workPhone, :workEmail, :statusId)'
    )->execute([
        ':personId' => $representativePersonId, ':occupation' => 'E013 Occupation',
        ':company' => 'E013 Company', ':position' => 'E013 Position', ':workPhone' => '022131313',
        ':workEmail' => 'e013.work@example.test', ':statusId' => $generalActive,
    ]);
    $representativeId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO families (family_code, display_name, status_id) '
        . 'VALUES (:familyCode, :name, :statusId)'
    )->execute([
        ':familyCode' => 'F91300001',
        ':name' => 'E013 Current Family',
        ':statusId' => $generalActive,
    ]);
    $familyId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO family_students (family_id, student_id, started_at) VALUES (:familyId, :studentId, :startedAt)'
    )->execute([':familyId' => $familyId, ':studentId' => $studentIds[2], ':startedAt' => '2040-01-01 00:00:00']);
    $connection->prepare(
        'INSERT INTO family_representatives '
        . '(family_id, representative_id, relationship_type_id, is_primary, started_at) '
        . 'VALUES (:familyId, :representativeId, :relationshipTypeId, 1, :startedAt)'
    )->execute([
        ':familyId' => $familyId, ':representativeId' => $representativeId,
        ':relationshipTypeId' => $relationshipTypeId, ':startedAt' => '2040-01-01 00:00:00',
    ]);
    $connection->prepare(
        'INSERT INTO family_addresses '
        . '(family_id, label, main_street, street_number, secondary_street, sector, reference, status_id) '
        . 'VALUES (:familyId, :label, :mainStreet, :number, :secondary, :sector, :reference, :statusId)'
    )->execute([
        ':familyId' => $familyId, ':label' => 'E013 Current Address', ':mainStreet' => 'E013 Current Street',
        ':number' => '13', ':secondary' => 'E013 Cross Street', ':sector' => 'E013 Current Sector',
        ':reference' => 'E013 Current Reference', ':statusId' => $generalActive,
    ]);
    $addressId = (int) $connection->lastInsertId();
    $connection->prepare(
        'INSERT INTO student_address_assignments '
        . '(family_id, family_address_id, student_id, started_at) '
        . 'VALUES (:familyId, :addressId, :studentId, :startedAt)'
    )->execute([
        ':familyId' => $familyId, ':addressId' => $addressId,
        ':studentId' => $studentIds[2], ':startedAt' => '2040-01-01 00:00:00',
    ]);

    $insertEnrollment = $connection->prepare(
        'INSERT INTO enrollments (student_id, family_id, academic_period_id, status_id, grade_id, section_id, '
        . 'billing_identification_type_id, billing_identification_number, billing_legal_name, billing_address, '
        . 'billing_email, billing_phone, has_medical_condition, medical_condition_detail, has_allergies, '
        . 'allergy_detail, takes_permanent_medication, medication_name, requires_special_care, special_care_detail, '
        . 'has_medical_insurance, insurance_provider, pediatrician_name, pediatrician_phone, medical_observations) '
        . 'VALUES (:studentId, :familyId, :periodId, :statusId, :gradeId, :sectionId, :documentTypeId, '
        . ':billingNumber, :billingName, :billingAddress, :billingEmail, :billingPhone, :hasCondition, '
        . ':conditionDetail, :hasAllergies, :allergyDetail, :takesMedication, :medicationName, :requiresCare, '
        . ':careDetail, :hasInsurance, :insuranceProvider, :pediatricianName, :pediatricianPhone, :observations)'
    );
    $saveEnrollment = static function (
        int $studentId,
        int $periodId,
        string $status,
        ?int $gradeId,
        ?int $sectionId,
        bool $completeAnnual,
        string $label,
    ) use ($insertEnrollment, $familyId, $documentTypeId, $enrollmentStatuses): void {
        $insertEnrollment->execute([
            ':studentId' => $studentId, ':familyId' => $familyId, ':periodId' => $periodId,
            ':statusId' => $enrollmentStatuses[$status], ':gradeId' => $gradeId, ':sectionId' => $sectionId,
            ':documentTypeId' => $completeAnnual ? $documentTypeId : null,
            ':billingNumber' => $completeAnnual ? 'E013-' . $label : null,
            ':billingName' => $completeAnnual ? $label . ' Billing' : null,
            ':billingAddress' => $completeAnnual ? $label . ' Billing Address' : null,
            ':billingEmail' => $completeAnnual ? strtolower($label) . '@example.test' : null,
            ':billingPhone' => $completeAnnual ? '0991313131' : null,
            ':hasCondition' => $completeAnnual ? 1 : null,
            ':conditionDetail' => $completeAnnual ? $label . ' condition' : null,
            ':hasAllergies' => $completeAnnual ? 1 : null,
            ':allergyDetail' => $completeAnnual ? $label . ' allergy' : null,
            ':takesMedication' => $completeAnnual ? 1 : null,
            ':medicationName' => $completeAnnual ? $label . ' medication' : null,
            ':requiresCare' => $completeAnnual ? 1 : null,
            ':careDetail' => $completeAnnual ? $label . ' care' : null,
            ':hasInsurance' => $completeAnnual ? 1 : null,
            ':insuranceProvider' => $completeAnnual ? $label . ' insurance' : null,
            ':pediatricianName' => $completeAnnual ? $label . ' doctor' : null,
            ':pediatricianPhone' => $completeAnnual ? '0991414141' : null,
            ':observations' => $completeAnnual ? $label . ' observation' : null,
        ]);
    };
    $saveEnrollment($studentIds[2], $activePeriodId, 'DRAFT', $gradeOneId, $sectionOneId, false, 'CurrentDraft');
    $saveEnrollment($studentIds[3], $activePeriodId, 'SUBMITTED', $gradeOneId, null, true, 'Current');
    $saveEnrollment($studentIds[4], $activePeriodId, 'COMPLETED', null, null, false, 'CurrentComplete');
    $saveEnrollment($studentIds[5], $activePeriodId, 'CANCELLED', $gradeTwoId, null, false, 'CurrentCancel');
    $saveEnrollment($studentIds[6], $activePeriodId, 'DRAFT', null, null, false, 'Inactive');
    $saveEnrollment($studentIds[2], $historicalPeriodId, 'COMPLETED', $gradeTwoId, null, true, 'Historical');

    $trackedTables = ['academic_periods', 'students', 'enrollments', 'families', 'family_students',
        'family_representatives', 'family_addresses', 'student_address_assignments'];
    $before = [];
    foreach ($trackedTables as $table) {
        $before[$table] = (int) $connection->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    $periodResolver = new ResolveEnrollmentReportingPeriod(new PdoAcademicPeriodRepository($manager));
    $periods = (new GetEnrollmentReportingPeriods(new PdoAcademicPeriodReportingQuery($manager)))->handle();
    assertIntegration(
        $periods->defaultAcademicPeriodId === $activePeriodId
        && $periodResolver->handle($historicalPeriodId)->status->value === 'INACTIVE',
        'E013 reporting AcademicPeriod options default or explicit INACTIVE selection is incorrect.'
    );

    $summary = (new GetEnrollmentSummaryReport(
        $periodResolver,
        new PdoEnrollmentSummaryQuery($manager),
    ))->handle($activePeriodId);
    $summaryStatuses = [];
    foreach ($summary->rows as $row) {
        $summaryStatuses[$row->status->value] = ($summaryStatuses[$row->status->value] ?? 0) + $row->count;
    }
    assertIntegration(
        $summary->total === 5
        && ($summaryStatuses['DRAFT'] ?? 0) === 2
        && ($summaryStatuses['SUBMITTED'] ?? 0) === 1
        && ($summaryStatuses['COMPLETED'] ?? 0) === 1
        && ($summaryStatuses['CANCELLED'] ?? 0) === 1
        && count($summaryStatuses) === 4
        && count(array_filter($summary->rows, static fn ($row): bool => $row->gradeId === null)) === 2,
        'E013 Enrollment Summary did not count exact existing Enrollments, statuses or null placement.'
    );

    $studentRows = (new GetStudentEnrollmentReport(
        $periodResolver,
        new PdoStudentEnrollmentListQuery($manager),
    ))->handle($activePeriodId);
    $directoryRows = (new GetStudentRepresentativeDirectory(
        $periodResolver,
        new PdoStudentRepresentativeDirectoryQuery($manager),
    ))->handle($activePeriodId);
    $billingRows = (new GetStudentBillingReport(
        $periodResolver,
        new PdoStudentBillingReportQuery($manager),
    ))->handle($activePeriodId);
    $medicalRows = (new GetStudentMedicalReport(
        $periodResolver,
        new PdoStudentMedicalReportQuery($manager),
    ))->handle($activePeriodId);
    $activeStudentCount = (int) $connection->query(
        'SELECT COUNT(*) FROM students s INNER JOIN statuses status_row ON status_row.id = s.status_id '
        . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
        . "WHERE status_type.code = 'GENERAL_STATUS' AND status_row.code = 'ACTIVE'"
    )->fetchColumn();
    assertIntegration(
        count($studentRows) === $activeStudentCount
        && count($directoryRows) === $activeStudentCount
        && count($billingRows) === $activeStudentCount
        && count($medicalRows) === $activeStudentCount,
        'E013 reports 2-5 did not return exactly one row per ACTIVE Student.'
    );
    $byStudent = static function (array $rows, int $studentId): object {
        $matches = array_values(array_filter($rows, static fn ($row): bool => $row->studentId === $studentId));
        assertIntegration(count($matches) === 1, 'E013 report did not return exactly one expected Student row.');

        return $matches[0];
    };
    assertIntegration(
        $byStudent($studentRows, $studentIds[1])->status->value === 'NOT STARTED'
        && $byStudent($studentRows, $studentIds[2])->status->value === 'DRAFT'
        && $byStudent($studentRows, $studentIds[3])->status->value === 'SUBMITTED'
        && $byStudent($studentRows, $studentIds[4])->status->value === 'COMPLETED'
        && $byStudent($studentRows, $studentIds[5])->status->value === 'CANCELLED'
        && count(array_filter($studentRows, static fn ($row): bool => $row->studentId === $studentIds[6])) === 0,
        'E013 ACTIVE Student population or reporting status mapping is incorrect.'
    );

    $directory = $byStudent($directoryRows, $studentIds[2]);
    assertIntegration(
        $directory->gradeId === $gradeOneId
        && $directory->sectionId === $sectionOneId
        && $directory->representativeMobilePhone === '0991300000'
        && $directory->representativeWorkEmail === 'e013.work@example.test'
        && str_contains((string) $directory->studentAddress, 'E013 Current Street'),
        'E013 Directory did not combine selected annual placement with current Representative contacts and Address.'
    );
    $billing = $byStudent($billingRows, $studentIds[3]);
    $medical = $byStudent($medicalRows, $studentIds[3]);
    assertIntegration(
        $billing->legalName === 'Current Billing'
        && $medical->medicalConditionDetail === 'Current condition'
        && $byStudent($billingRows, $studentIds[1])->legalName === null
        && $byStudent($medicalRows, $studentIds[1])->hasMedicalCondition === null,
        'E013 Billing or Medical selected-period values and valid blanks are incorrect.'
    );

    $historicalDirectoryRows = (new PdoStudentRepresentativeDirectoryQuery($manager))->fetch($historicalPeriodId);
    $historicalBillingRows = (new PdoStudentBillingReportQuery($manager))->fetch($historicalPeriodId);
    $historicalMedicalRows = (new PdoStudentMedicalReportQuery($manager))->fetch($historicalPeriodId);
    $historicalDirectory = $byStudent($historicalDirectoryRows, $studentIds[2]);
    assertIntegration(
        $historicalDirectory->gradeId === $gradeTwoId
        && $historicalDirectory->status->value === 'COMPLETED'
        && $historicalDirectory->representativeMobilePhone === '0991300000'
        && str_contains((string) $historicalDirectory->studentAddress, 'E013 Current Street')
        && $byStudent($historicalBillingRows, $studentIds[2])->legalName === 'Historical Billing'
        && $byStudent($historicalMedicalRows, $studentIds[2])->observations === 'Historical observation',
        'E013 historical report mixed annual and current live data incorrectly.'
    );

    foreach ($trackedTables as $table) {
        assertIntegration(
            $before[$table] === (int) $connection->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(),
            "E013 reporting query unexpectedly wrote to {$table}."
        );
    }
    $plans = [
        'SELECT e.id FROM enrollments e WHERE e.academic_period_id = ' . $activePeriodId,
        'SELECT s.id FROM students s LEFT JOIN enrollments e ON e.student_id = s.id '
            . 'AND e.academic_period_id = ' . $activePeriodId . ' ORDER BY s.id',
        'SELECT s.id FROM students s LEFT JOIN family_students fs ON fs.student_id = s.id AND fs.ended_at IS NULL '
            . 'LEFT JOIN family_representatives fr ON fr.family_id = fs.family_id '
            . 'AND fr.ended_at IS NULL AND fr.is_primary = 1 ORDER BY s.id',
    ];
    foreach ($plans as $sql) {
        $plan = $connection->query('EXPLAIN ' . $sql)->fetchAll(PDO::FETCH_ASSOC);
        assertIntegration($plan !== [], 'E013 reporting EXPLAIN returned no query-plan rows.');
    }
}

function mariaDbE015Phase6Workbook(
    string $familyCode,
    string $displayName,
    ?string $password = 'ClaveSegura9',
    string $representativeDocument = 'E015-P6-REP-001',
    string $studentCode = 'E015-P6-STUDENT-001',
    ?string $studentDocument = null,
    bool $isPrimary = true,
    string $representativeSexCode = 'TEST',
    string $studentSexCode = 'TEST',
    string $relationshipTypeCode = 'DISPOSABLE_TEST_RELATIONSHIP',
    ?DateTimeImmutable $representativeStartedAt = null,
    ?DateTimeImmutable $studentStartedAt = null,
): \App\BulkImport\Application\Dto\BulkImportWorkbook {
    $code = new FamilyCode($familyCode);
    $representativeStartedAt ??= new DateTimeImmutable(
        '2026-09-01 12:13:14',
        new DateTimeZone('UTC'),
    );
    $studentStartedAt ??= new DateTimeImmutable(
        '2026-09-01 12:13:15',
        new DateTimeZone('UTC'),
    );

    return new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        [new \App\BulkImport\Application\Dto\FamilyWorkbookRow(2, $code, $displayName)],
        [new \App\BulkImport\Application\Dto\RepresentativeWorkbookRow(
            2,
            $code,
            'E015',
            'MariaDB',
            'Representative',
            'Phase6',
            new DateTimeImmutable('1985-01-02', new DateTimeZone('UTC')),
            $representativeSexCode,
            'TEST',
            $representativeDocument,
            'e015.phase6@example.test',
            $relationshipTypeCode,
            $isPrimary,
            $representativeStartedAt,
            $password === null
                ? null
                : new \App\BulkImport\Application\Dto\SensitivePlaintextPassword($password),
        )],
        [new \App\BulkImport\Application\Dto\StudentWorkbookRow(
            2,
            $code,
            'E015',
            null,
            'Student',
            'Phase6',
            new DateTimeImmutable('2015-03-04', new DateTimeZone('UTC')),
            $studentSexCode,
            $studentDocument === null ? null : 'TEST',
            $studentDocument,
            new InstitutionalCode($studentCode),
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            $studentStartedAt,
        )],
    );
}

function runMariaDbBulkImportApplicationScenario(
    ConnectionManager $manager,
    PDO $connection,
): void {
    $persons = new PdoPersonRepository($manager);
    $representatives = new PdoRepresentativeRepository($manager);
    $users = new PdoUserRepository($manager);
    $students = new PdoStudentRepository($manager);
    $families = new PdoFamilyRepository($manager);
    $policy = new RepresentativePasswordPolicy();
    $reader = new MariaDbBulkImportWorkbookReader(
        mariaDbE015Phase6Workbook('F92600001', 'E015 Phase 6 MariaDB Family'),
    );
    $matcher = new \App\BulkImport\Application\Planning\BulkImportMatcher(
        new \App\BulkImport\Infrastructure\Persistence\PdoBulkImportCatalogResolver($manager),
        $persons,
        $representatives,
        $users,
        $students,
        $families,
        $policy,
    );
    $createPerson = new CreatePerson($persons);
    $getPerson = new \App\Person\Application\GetPerson($persons);
    $createRepresentative = new CreateRepresentative($persons, $representatives);
    $hasher = new NativePasswordHasher();
    $createUser = new CreateRepresentativeUser(
        $representatives,
        $persons,
        $users,
        $hasher,
        $policy,
    );
    $createAccess = new CreateRepresentativeAccess(
        $createPerson,
        $getPerson,
        $createRepresentative,
        $createUser,
    );
    $relationshipTypes = new PdoRelationshipTypeLookup($manager);
    $createFamily = new CreateFamily(
        $families,
        $representatives,
        $relationshipTypes,
        new \App\Family\Infrastructure\Generation\RandomFamilyCodeGenerator(),
    );
    $studentCoordinator = new \App\Family\Application\Orchestration\StudentFamilyCoordinator(
        $createPerson,
        $getPerson,
        new CreateStudent($persons, $students),
        new \App\Student\Application\GetStudent($students),
        new AddStudentToFamily($families, $students),
    );
    $apply = new \App\BulkImport\Application\ApplyBulkImport(
        $reader,
        $matcher,
        new PdoTransactionRunner($manager),
        $createAccess,
        $createRepresentative,
        $createUser,
        $createFamily,
        new \App\Family\Application\AddRepresentativeToFamily(
            $families,
            $representatives,
            $relationshipTypes,
        ),
        $studentCoordinator,
    );
    $preview = new \App\BulkImport\Application\PreviewBulkImport($reader, $matcher);
    $trackedTables = [
        'persons',
        'representatives',
        'users',
        'students',
        'families',
        'family_representatives',
        'family_students',
    ];
    $counts = static function () use ($connection, $trackedTables): array {
        $result = [];
        foreach ($trackedTables as $table) {
            $result[$table] = (int) $connection->query(
                sprintf('SELECT COUNT(*) FROM %s', $table),
            )->fetchColumn();
        }

        return $result;
    };
    $before = $counts();
    $today = new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC'));
    $previewResult = $preview->handle('canonical-workbook', $today);
    assertIntegration(
        $previewResult->families === 1
        && $previewResult->new === 3
        && $previewResult->conflicts === 0
        && $counts() === $before,
        'E015 Phase 6 Preview was not read-only or did not classify the complete new Family.'
    );

    $first = $apply->handle(
        'canonical-workbook',
        $today,
    );
    assertIntegration(
        count($first->families) === 1
        && $first->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New,
        'E015 Phase 6 Apply did not commit the complete new Family.'
    );
    $familyId = (int) $connection->query(
        "SELECT id FROM families WHERE family_code = 'F92600001'"
    )->fetchColumn();
    $physical = $connection->query(
        "SELECT p.id AS person_id, r.id AS representative_id, u.id AS user_id, "
        . "u.password_hash, s.id AS student_id, fr.id AS representative_membership_id, "
        . "fs.id AS student_membership_id, fr.is_primary, "
        . "DATE_FORMAT(fr.started_at, '%Y-%m-%d %H:%i:%s') AS representative_started_at, "
        . "DATE_FORMAT(fs.started_at, '%Y-%m-%d %H:%i:%s') AS student_started_at "
        . "FROM families f "
        . "INNER JOIN family_representatives fr ON fr.family_id = f.id AND fr.ended_at IS NULL "
        . "INNER JOIN representatives r ON r.id = fr.representative_id "
        . "INNER JOIN persons p ON p.id = r.person_id "
        . "INNER JOIN users u ON u.person_id = p.id "
        . "INNER JOIN family_students fs ON fs.family_id = f.id AND fs.ended_at IS NULL "
        . "INNER JOIN students s ON s.id = fs.student_id "
        . "WHERE f.family_code = 'F92600001'"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $familyId > 0
        && $physical !== false
        && (int) $physical['person_id'] > 0
        && (int) $physical['representative_id'] > 0
        && (int) $physical['user_id'] > 0
        && (int) $physical['student_id'] > 0
        && (int) $physical['representative_membership_id'] > 0
        && (int) $physical['student_membership_id'] > 0
        && (int) $physical['is_primary'] === 1
        && $physical['representative_started_at'] === '2026-09-01 12:13:14'
        && $physical['student_started_at'] === '2026-09-01 12:13:15'
        && $physical['password_hash'] !== 'ClaveSegura9'
        && $hasher->verify('ClaveSegura9', $physical['password_hash']),
        'E015 Phase 6 physical Aggregate AUTO_INCREMENT UTC status or password evidence failed.'
    );

    $afterFirst = $counts();
    $originalHash = (string) $physical['password_hash'];
    $reader->replace(mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'OtraClaveValida9',
    ));
    $second = $apply->handle(
        'canonical-workbook',
        new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
    );
    $currentHash = (string) $connection->query(
        "SELECT u.password_hash FROM users u INNER JOIN persons p ON p.id = u.person_id "
        . "WHERE p.document_number = 'E015-P6-REP-001'"
    )->fetchColumn();
    assertIntegration(
        count($second->families) === 1
        && $second->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::AlreadyExists
        && $counts() === $afterFirst
        && $currentHash === $originalHash,
        'E015 Phase 6 exact reimport duplicated data or replaced an existing password.'
    );

    $existingBase = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
    );
    $additional = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'NuevaClave9',
        'E015-P6-REP-002',
        'E015-P6-STUDENT-002',
        null,
        false,
    );
    $reader->replace(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $existingBase->families,
        [$existingBase->representatives[0], $additional->representatives[0]],
        [$existingBase->students[0], $additional->students[0]],
    ));
    $beforeExistingFamilyAdditions = $counts();
    $existingFamilyAdditions = $apply->handle(
        'canonical-workbook',
        new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
    );
    $afterExistingFamilyAdditions = $counts();
    assertIntegration(
        $existingFamilyAdditions->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterExistingFamilyAdditions['persons'] === $beforeExistingFamilyAdditions['persons'] + 2
        && $afterExistingFamilyAdditions['representatives']
            === $beforeExistingFamilyAdditions['representatives'] + 1
        && $afterExistingFamilyAdditions['users'] === $beforeExistingFamilyAdditions['users'] + 1
        && $afterExistingFamilyAdditions['students'] === $beforeExistingFamilyAdditions['students'] + 1
        && $afterExistingFamilyAdditions['families'] === $beforeExistingFamilyAdditions['families']
        && $afterExistingFamilyAdditions['family_representatives']
            === $beforeExistingFamilyAdditions['family_representatives'] + 1
        && $afterExistingFamilyAdditions['family_students']
            === $beforeExistingFamilyAdditions['family_students'] + 1,
        'E015 Phase 6 existing Family additions did not create exactly one Representative and Student.'
    );

    $today = new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC'));
    $seedPerson = static function (
        string $document,
        CreatePerson $createPerson,
        DateTimeImmutable $today,
    ): int {
        return $createPerson->handle(new \App\Person\Application\Dto\CreatePersonInput(
            'Existing',
            null,
            'Person',
            null,
            1,
            $document,
            new DateTimeImmutable('1984-04-05', new DateTimeZone('UTC')),
            1,
            null,
            null,
            'existing.person@example.test',
            null,
            null,
            PersonStatus::Active,
        ), $today)->id;
    };

    $existingPersonId = $seedPerson('E015-P6-REP-003', $createPerson, $today);
    $reader->replace(mariaDbE015Phase6Workbook(
        'F92600003',
        'E015 Existing Person Family',
        'ClavePersona9',
        'E015-P6-REP-003',
        'E015-P6-STUDENT-003',
    ));
    $beforeExistingPerson = $counts();
    $existingPersonResult = $apply->handle('canonical-workbook', $today);
    $afterExistingPerson = $counts();
    assertIntegration(
        $existingPersonId > 0
        && $existingPersonResult->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterExistingPerson['persons'] === $beforeExistingPerson['persons'] + 1
        && $afterExistingPerson['representatives'] === $beforeExistingPerson['representatives'] + 1
        && $afterExistingPerson['users'] === $beforeExistingPerson['users'] + 1,
        'E015 Phase 6 did not reuse an existing Person for a new Representative and User.'
    );

    $existingRepresentativePersonId = $seedPerson('E015-P6-REP-004', $createPerson, $today);
    $existingRepresentative = $createRepresentative->handle(new CreateRepresentativeInput(
        $existingRepresentativePersonId,
        null,
        null,
        null,
        null,
        null,
        RepresentativeStatus::Active,
    ));
    $reader->replace(mariaDbE015Phase6Workbook(
        'F92600004',
        'E015 Existing Representative Family',
        'ClaveAcceso9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-004',
    ));
    $beforeMissingUser = $counts();
    $missingUserResult = $apply->handle('canonical-workbook', $today);
    $afterMissingUser = $counts();
    assertIntegration(
        $existingRepresentative->id > 0
        && $missingUserResult->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterMissingUser['persons'] === $beforeMissingUser['persons'] + 1
        && $afterMissingUser['representatives'] === $beforeMissingUser['representatives']
        && $afterMissingUser['users'] === $beforeMissingUser['users'] + 1,
        'E015 Phase 6 did not provision the missing User for an existing Representative.'
    );

    $existingRepresentativeBase = mariaDbE015Phase6Workbook(
        'F92600004',
        'E015 Existing Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-004',
    );
    $multiRoleStudent = mariaDbE015Phase6Workbook(
        'F92600004',
        'E015 Existing Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-MULTI',
        'E015-P6-REP-004',
    );
    $reader->replace(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $existingRepresentativeBase->families,
        $existingRepresentativeBase->representatives,
        [$existingRepresentativeBase->students[0], $multiRoleStudent->students[0]],
    ));
    $beforeMultiRole = $counts();
    $multiRoleResult = $apply->handle('canonical-workbook', $today);
    $afterMultiRole = $counts();
    assertIntegration(
        $multiRoleResult->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterMultiRole['persons'] === $beforeMultiRole['persons']
        && $afterMultiRole['students'] === $beforeMultiRole['students'] + 1
        && (int) $connection->query(
            "SELECT COUNT(*) FROM representatives r INNER JOIN students s ON s.person_id = r.person_id "
            . "INNER JOIN persons p ON p.id = r.person_id WHERE p.document_number = 'E015-P6-REP-004'"
        )->fetchColumn() === 1,
        'E015 Phase 6 did not reuse one existing Representative Person for the Student multi-role.'
    );

    $reader->replace(mariaDbE015Phase6Workbook(
        'F92600005',
        'E015 Shared Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-005',
    ));
    $beforeSharedRepresentative = $counts();
    $sharedRepresentative = $apply->handle('canonical-workbook', $today);
    $afterSharedRepresentative = $counts();
    assertIntegration(
        $sharedRepresentative->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterSharedRepresentative['persons'] === $beforeSharedRepresentative['persons'] + 1
        && $afterSharedRepresentative['representatives'] === $beforeSharedRepresentative['representatives']
        && $afterSharedRepresentative['users'] === $beforeSharedRepresentative['users']
        && $afterSharedRepresentative['families'] === $beforeSharedRepresentative['families'] + 1,
        'E015 Phase 6 did not reuse one Representative and User across two Families.'
    );

    $familyA = mariaDbE015Phase6Workbook(
        'F92600011',
        'E015 Isolation A',
        'ClaveA9',
        'E015-P6-REP-A',
        'E015-P6-STUDENT-A',
    );
    $familyB = mariaDbE015Phase6Workbook(
        'F92600012',
        'E015 Isolation B',
        'ClaveB9',
        'E015-P6-REP-B',
        'E015-P6-STUDENT-B',
    );
    $familyC = mariaDbE015Phase6Workbook(
        'F92600013',
        'E015 Isolation C',
        'ClaveC9',
        'E015-P6-REP-C',
        'E015-P6-STUDENT-C',
    );
    $isolationReader = new MariaDbBulkImportWorkbookReader(
        new \App\BulkImport\Application\Dto\BulkImportWorkbook(
            [$familyC->families[0], $familyB->families[0], $familyA->families[0]],
            [$familyC->representatives[0], $familyB->representatives[0], $familyA->representatives[0]],
            [$familyC->students[0], $familyB->students[0], $familyA->students[0]],
        ),
    );
    $failingFamilies = new MariaDbFailOneFamilyAfterSaveRepository($families, 'F92600012');
    $isolationMatcher = new \App\BulkImport\Application\Planning\BulkImportMatcher(
        new \App\BulkImport\Infrastructure\Persistence\PdoBulkImportCatalogResolver($manager),
        $persons,
        $representatives,
        $users,
        $students,
        $failingFamilies,
        $policy,
    );
    $isolationStudentCoordinator = new \App\Family\Application\Orchestration\StudentFamilyCoordinator(
        $createPerson,
        $getPerson,
        new CreateStudent($persons, $students),
        new \App\Student\Application\GetStudent($students),
        new AddStudentToFamily($failingFamilies, $students),
    );
    $isolationApply = new \App\BulkImport\Application\ApplyBulkImport(
        $isolationReader,
        $isolationMatcher,
        new PdoTransactionRunner($manager),
        $createAccess,
        $createRepresentative,
        $createUser,
        new CreateFamily(
            $failingFamilies,
            $representatives,
            $relationshipTypes,
            new \App\Family\Infrastructure\Generation\RandomFamilyCodeGenerator(),
        ),
        new \App\Family\Application\AddRepresentativeToFamily(
            $failingFamilies,
            $representatives,
            $relationshipTypes,
        ),
        $isolationStudentCoordinator,
    );
    $beforeIsolation = $counts();
    $isolation = $isolationApply->handle('canonical-workbook', $today);
    $afterIsolation = $counts();
    assertIntegration(
        array_map(static fn ($item): string => $item->familyCode, $isolation->families)
            === ['F92600011', 'F92600012', 'F92600013']
        && array_map(static fn ($item) => $item->classification, $isolation->families) === [
            \App\BulkImport\Application\Dto\BulkImportClassification::New,
            \App\BulkImport\Application\Dto\BulkImportClassification::Conflict,
            \App\BulkImport\Application\Dto\BulkImportClassification::New,
        ]
        && $afterIsolation['persons'] === $beforeIsolation['persons'] + 4
        && $afterIsolation['representatives'] === $beforeIsolation['representatives'] + 2
        && $afterIsolation['users'] === $beforeIsolation['users'] + 2
        && $afterIsolation['students'] === $beforeIsolation['students'] + 2
        && $afterIsolation['families'] === $beforeIsolation['families'] + 2
        && $afterIsolation['family_representatives']
            === $beforeIsolation['family_representatives'] + 2
        && $afterIsolation['family_students'] === $beforeIsolation['family_students'] + 2
        && $families->findByCode(new FamilyCode('F92600011')) !== null
        && $families->findByCode(new FamilyCode('F92600012')) === null
        && $families->findByCode(new FamilyCode('F92600013')) !== null
        && !str_contains($isolation->families[1]->message, 'Synthetic'),
        'E015 Phase 6 FamilyCode ordering rollback isolation or safe failure mapping failed.'
    );

    $assertConflictWithoutWrites = static function (
        \App\BulkImport\Application\Dto\BulkImportWorkbook $workbook,
        string $label,
    ) use ($reader, $apply, $counts, $today): void {
        $reader->replace($workbook);
        $beforeConflict = $counts();
        $result = $apply->handle('canonical-workbook', $today);
        assertIntegration(
            count($result->families) === 1
            && $result->families[0]->classification
                === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
            && $counts() === $beforeConflict,
            'E015 Phase 6 conflict scenario changed physical rows: ' . $label,
        );
    };

    $membershipMismatch = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
        'E015-P6-REP-001',
        'E015-P6-STUDENT-001',
        null,
        true,
        'TEST',
        'TEST',
        'DISPOSABLE_TEST_RELATIONSHIP',
        new DateTimeImmutable('2026-09-01 12:13:19', new DateTimeZone('UTC')),
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $membershipMismatch->families,
        $membershipMismatch->representatives,
        [],
    ), 'Representative membership mismatch');

    $primaryReplacement = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'ClavePrimary9',
        'E015-P6-REP-PRIMARY-REPLACEMENT',
        'E015-P6-UNUSED-PRIMARY',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $primaryReplacement->families,
        $primaryReplacement->representatives,
        [],
    ), 'Primary replacement');

    $studentIdentificationMismatch = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
        'E015-P6-REP-001',
        'E015-P6-STUDENT-001',
        'E015-P6-WRONG-STUDENT-DOCUMENT',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $studentIdentificationMismatch->families,
        [],
        $studentIdentificationMismatch->students,
    ), 'Student identification mismatch');

    $studentMembershipMismatch = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
        'E015-P6-REP-001',
        'E015-P6-STUDENT-001',
        null,
        true,
        'TEST',
        'TEST',
        'DISPOSABLE_TEST_RELATIONSHIP',
        null,
        new DateTimeImmutable('2026-09-01 12:13:20', new DateTimeZone('UTC')),
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $studentMembershipMismatch->families,
        [],
        $studentMembershipMismatch->students,
    ), 'Student membership startedAt mismatch');

    $studentOtherFamily = mariaDbE015Phase6Workbook(
        'F92600006',
        'E015 Student Other Family',
        'ClaveOtra9',
        'E015-P6-REP-006',
        'E015-P6-STUDENT-001',
    );
    $assertConflictWithoutWrites($studentOtherFamily, 'Student active in another Family');

    $inactiveCatalog = mariaDbE015Phase6Workbook(
        'F92600007',
        'E015 Inactive Catalog Family',
        'ClaveCatalogo9',
        'E015-P6-REP-007',
        'E015-P6-STUDENT-007',
        null,
        true,
        'TEST',
        'TEST',
        'DISPOSABLE_INACTIVE_RELATIONSHIP',
    );
    $assertConflictWithoutWrites($inactiveCatalog, 'inactive catalog');

    $activeGeneralStatusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $inactiveGeneralStatusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    $disabledUserStatusId = (int) $connection->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'DISABLED'"
    )->fetchColumn();

    $makeRacedApply = static function (
        MariaDbBulkImportWorkbookReader $raceReader,
        MariaDbBeforeTransactionRunner $raceRunner,
    ) use (
        $matcher,
        $createAccess,
        $createRepresentative,
        $createUser,
        $createFamily,
        $families,
        $representatives,
        $relationshipTypes,
        $studentCoordinator,
    ): \App\BulkImport\Application\ApplyBulkImport {
        return new \App\BulkImport\Application\ApplyBulkImport(
            $raceReader,
            $matcher,
            $raceRunner,
            $createAccess,
            $createRepresentative,
            $createUser,
            $createFamily,
            new \App\Family\Application\AddRepresentativeToFamily(
                $families,
                $representatives,
                $relationshipTypes,
            ),
            $studentCoordinator,
        );
    };

    $familyRacePrimaryPersonId = $seedPerson('E015-P6-FAMILY-RACE-OWNER', $createPerson, $today);
    $familyRacePrimary = $createRepresentative->handle(new CreateRepresentativeInput(
        $familyRacePrimaryPersonId,
        null,
        null,
        null,
        null,
        null,
        RepresentativeStatus::Active,
    ));
    $familyRaceRelationshipTypeId = (int) $connection->query(
        "SELECT id FROM relationship_types WHERE code = 'DISPOSABLE_TEST_RELATIONSHIP'"
    )->fetchColumn();
    $familyRaceWorkbook = mariaDbE015Phase6Workbook(
        'F92600021',
        'E015 Family Race',
        'ClaveRace9',
        'E015-P6-REP-RACE-FAMILY',
        'E015-P6-STUDENT-RACE-FAMILY',
    );
    $familyRaceReader = new MariaDbBulkImportWorkbookReader($familyRaceWorkbook);
    $familyRaceRunner = new MariaDbBeforeTransactionRunner(
        new PdoTransactionRunner($manager),
        static function () use (
            $createFamily,
            $familyRacePrimary,
            $familyRaceRelationshipTypeId,
        ): void {
            $createFamily->handleWithCode(new \App\Family\Application\Dto\CreateFamilyWithCodeInput(
                'F92600021',
                'E015 Family Race',
                \App\Family\Domain\FamilyStatus::Active,
                $familyRacePrimary->id,
                $familyRaceRelationshipTypeId,
                new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')),
            ));
        },
    );
    $beforeFamilyRace = $counts();
    $familyRace = $makeRacedApply($familyRaceReader, $familyRaceRunner)->handle(
        'canonical-workbook',
        $today,
    );
    $afterFamilyRace = $counts();
    assertIntegration(
        $familyRaceRunner->calls() === 1
        && $familyRace->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
        && $familyRace->families[0]->issues[0]->category
            === \App\BulkImport\Application\Dto\BulkImportIssueCategory::ConcurrentChange
        && $afterFamilyRace['families'] === $beforeFamilyRace['families'] + 1
        && $afterFamilyRace['persons'] === $beforeFamilyRace['persons']
        && $afterFamilyRace['family_representatives']
            === $beforeFamilyRace['family_representatives'] + 1
        && $afterFamilyRace['family_students'] === $beforeFamilyRace['family_students'],
        'E015 Phase 6 Family-created-after-matching race was not rejected without partial import writes.'
    );

    $personRaceWorkbook = mariaDbE015Phase6Workbook(
        'F92600022',
        'E015 Person Race',
        'ClaveRace9',
        'E015-P6-REP-RACE-PERSON',
        'E015-P6-STUDENT-RACE-PERSON',
    );
    $personRaceReader = new MariaDbBulkImportWorkbookReader($personRaceWorkbook);
    $personRaceRunner = new MariaDbBeforeTransactionRunner(
        new PdoTransactionRunner($manager),
        static function () use ($connection, $activeGeneralStatusId): void {
            $insert = $connection->prepare(
                'INSERT INTO persons '
                . '(first_name, first_surname, document_type_id, document_number, birth_date, sex_id, email, status_id) '
                . 'VALUES (:firstName, :firstSurname, 1, :documentNumber, :birthDate, 2, :email, :statusId)'
            );
            $insert->execute([
                ':firstName' => 'Concurrent',
                ':firstSurname' => 'Identity',
                ':documentNumber' => 'E015-P6-REP-RACE-PERSON',
                ':birthDate' => '1980-01-01',
                ':email' => 'concurrent.identity@example.test',
                ':statusId' => $activeGeneralStatusId,
            ]);
        },
    );
    $beforePersonRace = $counts();
    $personRace = $makeRacedApply($personRaceReader, $personRaceRunner)->handle(
        'canonical-workbook',
        $today,
    );
    $afterPersonRace = $counts();
    assertIntegration(
        $personRaceRunner->calls() === 1
        && $personRace->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
        && $personRace->families[0]->issues[0]->category
            === \App\BulkImport\Application\Dto\BulkImportIssueCategory::ConcurrentChange
        && $afterPersonRace['persons'] === $beforePersonRace['persons'] + 1
        && $afterPersonRace['families'] === $beforePersonRace['families'],
        'E015 Phase 6 Person-identity race was not revalidated under locks.'
    );

    $loginRaceOwnerId = $seedPerson('E015-P6-LOGIN-RACE-OWNER', $createPerson, $today);
    $loginRaceWorkbook = mariaDbE015Phase6Workbook(
        'F92600023',
        'E015 Login Race',
        'ClaveRace9',
        'E015-P6-REP-RACE-LOGIN',
        'E015-P6-STUDENT-RACE-LOGIN',
    );
    $loginRaceReader = new MariaDbBulkImportWorkbookReader($loginRaceWorkbook);
    $loginRaceRunner = new MariaDbBeforeTransactionRunner(
        new PdoTransactionRunner($manager),
        static function () use ($connection, $loginRaceOwnerId): void {
            $hash = password_hash('LoginRaceOwner9', PASSWORD_DEFAULT);
            assertIntegration(is_string($hash), 'Unable to create disposable login-race hash.');
            $activeUserStatusId = (int) $connection->query(
                "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
                . "WHERE st.code = 'USER_STATUS' AND s.code = 'ACTIVE'"
            )->fetchColumn();
            $insert = $connection->prepare(
                'INSERT INTO users '
                . '(person_id, login_identifier, normalized_login_identifier, password_hash, status_id) '
                . 'VALUES (:personId, :login, :normalizedLogin, :passwordHash, :statusId)'
            );
            $insert->execute([
                ':personId' => $loginRaceOwnerId,
                ':login' => 'E015-P6-REP-RACE-LOGIN',
                ':normalizedLogin' => 'e015-p6-rep-race-login',
                ':passwordHash' => $hash,
                ':statusId' => $activeUserStatusId,
            ]);
        },
    );
    $beforeLoginRace = $counts();
    $loginRace = $makeRacedApply($loginRaceReader, $loginRaceRunner)->handle(
        'canonical-workbook',
        $today,
    );
    $afterLoginRace = $counts();
    assertIntegration(
        $loginRaceRunner->calls() === 1
        && $loginRace->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
        && $loginRace->families[0]->issues[0]->category
            === \App\BulkImport\Application\Dto\BulkImportIssueCategory::ConcurrentChange
        && $afterLoginRace['users'] === $beforeLoginRace['users'] + 1
        && $afterLoginRace['families'] === $beforeLoginRace['families'],
        'E015 Phase 6 LoginIdentifier race was not revalidated under locks.'
    );

    $studentRaceWorkbook = mariaDbE015Phase6Workbook(
        'F92600024',
        'E015 Student Race',
        'ClaveRace9',
        'E015-P6-REP-RACE-STUDENT',
        'E015-P6-STUDENT-RACE',
    );
    $studentRaceReader = new MariaDbBulkImportWorkbookReader($studentRaceWorkbook);
    $studentRaceRunner = new MariaDbBeforeTransactionRunner(
        new PdoTransactionRunner($manager),
        static function () use ($connection, $activeGeneralStatusId): void {
            $insertPerson = $connection->prepare(
                'INSERT INTO persons (first_name, first_surname, birth_date, sex_id, status_id) '
                . 'VALUES (:firstName, :firstSurname, :birthDate, 1, :statusId)'
            );
            $insertPerson->execute([
                ':firstName' => 'Concurrent',
                ':firstSurname' => 'Student',
                ':birthDate' => '2014-02-03',
                ':statusId' => $activeGeneralStatusId,
            ]);
            $personId = (int) $connection->lastInsertId();
            $insertStudent = $connection->prepare(
                'INSERT INTO students (person_id, institutional_code, admission_date, status_id) '
                . 'VALUES (:personId, :institutionalCode, :admissionDate, :statusId)'
            );
            $insertStudent->execute([
                ':personId' => $personId,
                ':institutionalCode' => 'E015-P6-STUDENT-RACE',
                ':admissionDate' => '2026-09-01',
                ':statusId' => $activeGeneralStatusId,
            ]);
        },
    );
    $beforeStudentRace = $counts();
    $studentRace = $makeRacedApply($studentRaceReader, $studentRaceRunner)->handle(
        'canonical-workbook',
        $today,
    );
    $afterStudentRace = $counts();
    assertIntegration(
        $studentRaceRunner->calls() === 1
        && $studentRace->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::New
        && $afterStudentRace['persons'] === $beforeStudentRace['persons'] + 2
        && $afterStudentRace['students'] === $beforeStudentRace['students'] + 1
        && $afterStudentRace['families'] === $beforeStudentRace['families'] + 1
        && $afterStudentRace['family_students'] === $beforeStudentRace['family_students'] + 1
        && (int) $connection->query(
            "SELECT COUNT(*) FROM students WHERE institutional_code = 'E015-P6-STUDENT-RACE'"
        )->fetchColumn() === 1,
        'E015 Phase 6 Student-code race did not reuse the concurrent Student without duplication.'
    );

    $connection->exec(
        "UPDATE families SET status_id = {$inactiveGeneralStatusId} WHERE family_code = 'F92600005'"
    );
    $assertConflictWithoutWrites(mariaDbE015Phase6Workbook(
        'F92600005',
        'E015 Shared Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-005',
    ), 'inactive Family');
    $connection->exec(
        "UPDATE families SET status_id = {$activeGeneralStatusId} WHERE family_code = 'F92600005'"
    );

    $connection->exec(
        "UPDATE persons SET sex_id = 2 WHERE document_number = 'E015-P6-REP-003'"
    );
    $assertConflictWithoutWrites(mariaDbE015Phase6Workbook(
        'F92600003',
        'E015 Existing Person Family',
        'IgnoredExisting9',
        'E015-P6-REP-003',
        'E015-P6-STUDENT-003',
    ), 'Person Sex mismatch');
    $connection->exec(
        "UPDATE persons SET sex_id = 1 WHERE document_number = 'E015-P6-REP-003'"
    );

    $connection->exec(
        "UPDATE persons SET status_id = {$inactiveGeneralStatusId} "
        . "WHERE document_number = 'E015-P6-REP-002'"
    );
    $inactivePersonWorkbook = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
        'E015-P6-REP-002',
        'E015-P6-UNUSED-INACTIVE-PERSON',
        null,
        false,
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $inactivePersonWorkbook->families,
        $inactivePersonWorkbook->representatives,
        [],
    ), 'inactive Person');

    $connection->exec(
        "UPDATE representatives r INNER JOIN persons p ON p.id = r.person_id "
        . "SET r.status_id = {$inactiveGeneralStatusId} WHERE p.document_number = 'E015-P6-REP-004'"
    );
    $inactiveRepresentativeWorkbook = mariaDbE015Phase6Workbook(
        'F92600004',
        'E015 Existing Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-UNUSED-INACTIVE-REP',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $inactiveRepresentativeWorkbook->families,
        $inactiveRepresentativeWorkbook->representatives,
        [],
    ), 'inactive Representative');

    $connection->exec(
        "UPDATE users u INNER JOIN persons p ON p.id = u.person_id "
        . "SET u.status_id = {$disabledUserStatusId} WHERE p.document_number = 'E015-P6-REP-003'"
    );
    $disabledUserWorkbook = mariaDbE015Phase6Workbook(
        'F92600003',
        'E015 Existing Person Family',
        'IgnoredExisting9',
        'E015-P6-REP-003',
        'E015-P6-UNUSED-DISABLED-USER',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $disabledUserWorkbook->families,
        $disabledUserWorkbook->representatives,
        [],
    ), 'non-access-capable User');

    $connection->exec(
        "UPDATE students SET status_id = {$inactiveGeneralStatusId} "
        . "WHERE institutional_code = 'E015-P6-STUDENT-002'"
    );
    $inactiveStudentWorkbook = mariaDbE015Phase6Workbook(
        'F92600001',
        'E015 Phase 6 MariaDB Family',
        'IgnoredExisting9',
        'E015-P6-REP-001',
        'E015-P6-STUDENT-002',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $inactiveStudentWorkbook->families,
        [],
        $inactiveStudentWorkbook->students,
    ), 'inactive Student');

    $connection->exec(
        "UPDATE persons p INNER JOIN students s ON s.person_id = p.id "
        . "SET p.status_id = {$inactiveGeneralStatusId} "
        . "WHERE s.institutional_code = 'E015-P6-STUDENT-005'"
    );
    $inactiveStudentPersonWorkbook = mariaDbE015Phase6Workbook(
        'F92600005',
        'E015 Shared Representative Family',
        'IgnoredExisting9',
        'E015-P6-REP-004',
        'E015-P6-STUDENT-005',
    );
    $assertConflictWithoutWrites(new \App\BulkImport\Application\Dto\BulkImportWorkbook(
        $inactiveStudentPersonWorkbook->families,
        [],
        $inactiveStudentPersonWorkbook->students,
    ), 'inactive Student Person');

    $loginOwnerPersonId = $seedPerson('E015-P6-LOGIN-OWNER', $createPerson, $today);
    $loginOwnerHash = password_hash('OwnerPassword9', PASSWORD_DEFAULT);
    assertIntegration(is_string($loginOwnerHash), 'Unable to create disposable login-owner hash.');
    $insertLoginOwner = $connection->prepare(
        'INSERT INTO users '
        . '(person_id, login_identifier, normalized_login_identifier, password_hash, status_id) '
        . 'VALUES (:personId, :login, :normalizedLogin, :passwordHash, :statusId)'
    );
    $insertLoginOwner->execute([
        ':personId' => $loginOwnerPersonId,
        ':login' => 'E015-P6-REP-LOGIN',
        ':normalizedLogin' => 'e015-p6-rep-login',
        ':passwordHash' => $loginOwnerHash,
        ':statusId' => (int) $connection->query(
            "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
            . "WHERE st.code = 'USER_STATUS' AND s.code = 'ACTIVE'"
        )->fetchColumn(),
    ]);
    $assertConflictWithoutWrites(mariaDbE015Phase6Workbook(
        'F92600008',
        'E015 Login Collision Family',
        'ClaveLogin9',
        'E015-P6-REP-LOGIN',
        'E015-P6-STUDENT-008',
    ), 'LoginIdentifier owned by another Person');

    $assertConflictWithoutWrites(mariaDbE015Phase6Workbook(
        'F92600009',
        'E015 Invalid Password Family',
        'bad',
        'E015-P6-REP-009',
        'E015-P6-STUDENT-009',
    ), 'invalid password for new User');

    $reader->replace(mariaDbE015Phase6Workbook('F92600001', 'Conflict Name'));
    $beforeDisplayNameConflict = $counts();
    $conflict = $apply->handle(
        'canonical-workbook',
        new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
    );
    assertIntegration(
        $conflict->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
        && $counts() === $beforeDisplayNameConflict,
        'E015 Phase 6 conflict did not preserve the previously committed Family exactly.'
    );

    $reader->replace(mariaDbE015Phase6Workbook(
        'F92600002',
        'E015 Phase 6 Missing Password',
        null,
    ));
    $beforeMissingPassword = $counts();
    $missingPassword = $apply->handle(
        'canonical-workbook',
        new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
    );
    assertIntegration(
        $missingPassword->families[0]->classification
            === \App\BulkImport\Application\Dto\BulkImportClassification::Conflict
        && $counts() === $beforeMissingPassword
        && (int) $connection->query(
            "SELECT COUNT(*) FROM families WHERE family_code = 'F92600002'"
        )->fetchColumn() === 0,
        'E015 Phase 6 missing-password conflict left partial physical data.'
    );

    runMariaDbBulkImportDeliveryScenario($manager, $connection);
}

function runMariaDbBulkImportDeliveryScenario(
    ConnectionManager $manager,
    PDO $connection,
): void {
    $persons = new PdoPersonRepository($manager);
    $representatives = new PdoRepresentativeRepository($manager);
    $users = new PdoUserRepository($manager);
    $students = new PdoStudentRepository($manager);
    $families = new PdoFamilyRepository($manager);
    $policy = new RepresentativePasswordPolicy();
    $today = new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC'));
    $reader = new \App\BulkImport\Infrastructure\Xlsx\OpenSpoutBulkImportWorkbookReader(
        new \App\BulkImport\Infrastructure\Xlsx\XlsxContainerPreflightInspector(),
        new \App\BulkImport\Application\ValidateBulkImportWorkbook($policy, $today),
    );
    $matcher = new \App\BulkImport\Application\Planning\BulkImportMatcher(
        new \App\BulkImport\Infrastructure\Persistence\PdoBulkImportCatalogResolver($manager),
        $persons,
        $representatives,
        $users,
        $students,
        $families,
        $policy,
    );
    $createPerson = new CreatePerson($persons);
    $getPerson = new \App\Person\Application\GetPerson($persons);
    $createRepresentative = new CreateRepresentative($persons, $representatives);
    $hasher = new NativePasswordHasher();
    $createUser = new CreateRepresentativeUser(
        $representatives,
        $persons,
        $users,
        $hasher,
        $policy,
    );
    $createAccess = new CreateRepresentativeAccess(
        $createPerson,
        $getPerson,
        $createRepresentative,
        $createUser,
    );
    $relationshipTypes = new PdoRelationshipTypeLookup($manager);
    $createFamily = new CreateFamily(
        $families,
        $representatives,
        $relationshipTypes,
        new \App\Family\Infrastructure\Generation\RandomFamilyCodeGenerator(),
    );
    $studentCoordinator = new \App\Family\Application\Orchestration\StudentFamilyCoordinator(
        $createPerson,
        $getPerson,
        new CreateStudent($persons, $students),
        new \App\Student\Application\GetStudent($students),
        new AddStudentToFamily($families, $students),
    );
    $apply = new \App\BulkImport\Application\ApplyBulkImport(
        $reader,
        $matcher,
        new PdoTransactionRunner($manager),
        $createAccess,
        $createRepresentative,
        $createUser,
        $createFamily,
        new \App\Family\Application\AddRepresentativeToFamily(
            $families,
            $representatives,
            $relationshipTypes,
        ),
        $studentCoordinator,
    );
    $preview = new \App\BulkImport\Application\PreviewBulkImport($reader, $matcher);

    $session = new \Tests\BulkImportDeliverySessionManager(1);
    $clock = new \Tests\BulkImportDeliveryClock(
        new DateTimeImmutable('2026-09-02 12:00:00', new DateTimeZone('UTC')),
    );
    $deliverySession = new \App\BulkImport\Application\Delivery\BulkImportDeliverySession(
        $session,
        $clock,
    );
    $csrf = new \App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager($session);
    $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'antares-e015-phase7-' . bin2hex(random_bytes(8));
    $temporaryFiles = new \App\BulkImport\Infrastructure\Filesystem\LocalBulkImportTemporaryFileStore(
        $temporaryDirectory,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public',
        static fn (string $source, string $destination): bool => copy($source, $destination),
    );
    $controller = new \App\BulkImport\Http\BulkImportController(
        $preview,
        $temporaryFiles,
        $deliverySession,
        new \App\BulkImport\Http\BulkImportErrorCsvWriter(),
        $csrf,
        $session,
        new PdoPersonFormOptionsProvider($manager),
        new PdoFamilyFormOptionsProvider($manager),
    );
    $applyController = new \App\BulkImport\Http\BulkImportApplyController(
        $apply,
        $temporaryFiles,
        $deliverySession,
        $csrf,
        $session,
        $clock,
    );
    $templateController = new \App\BulkImport\Http\BulkImportTemplateController(
        new \App\BulkImport\Http\BulkImportTemplateFile(
            dirname(__DIR__) . '/resources/templates/bulk-import/e015-family-import-v1.xlsx',
        ),
    );

    $trackedTables = [
        'persons', 'representatives', 'users', 'students', 'families',
        'family_representatives', 'family_students',
    ];
    $counts = static function () use ($connection, $trackedTables): array {
        $result = [];
        foreach ($trackedTables as $table) {
            $result[$table] = (int) $connection->query(
                sprintf('SELECT COUNT(*) FROM %s', $table),
            )->fetchColumn();
        }

        return $result;
    };
    $temporaryCount = static fn (): int => count(glob($temporaryDirectory . '/*.xlsx') ?: []);
    $request = static function (
        string $method,
        string $uri,
        array $post = [],
        array $files = [],
    ): void {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = $method === 'GET' ? $post : [];
        $_POST = $method === 'POST' ? $post : [];
        $_FILES = $files;
        http_response_code(200);
    };
    $upload = static fn (string $path): array => ['workbook' => [
        'name' => 'familias.xlsx',
        'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($path),
    ]];
    $fixtureFactory = new \Tests\BulkImportXlsxFixtureFactory();
    $workbook = static function (
        string $fileName,
        string $familyCode,
        string $displayName,
        string $representativeDocument,
        string $studentCode,
        string $password,
    ) use ($fixtureFactory): string {
        $sheets = $fixtureFactory->validSheets();
        $sheets['Familias'][1][0] = $familyCode;
        $sheets['Familias'][1][1] = $displayName;
        $sheets['Representantes'][1][0] = $familyCode;
        $sheets['Representantes'][1][6] = 'TEST';
        $sheets['Representantes'][1][7] = 'TEST';
        $sheets['Representantes'][1][8] = $representativeDocument;
        $sheets['Representantes'][1][9] = strtolower($familyCode) . '@example.test';
        $sheets['Representantes'][1][10] = 'DISPOSABLE_TEST_RELATIONSHIP';
        $sheets['Representantes'][1][13] = $password;
        $sheets['Estudiantes'][1][0] = $familyCode;
        $sheets['Estudiantes'][1][6] = 'TEST';
        $sheets['Estudiantes'][1][9] = $studentCode;

        return $fixtureFactory->writeWorkbook($fileName, $sheets);
    };

    $password = 'E015-SENTINEL-SECRET-9271!';
    $source = $workbook(
        'phase7-success.xlsx',
        'F92700001',
        'Familia Phase 7',
        'E015-P7-REP-001',
        'E015-P7-STUDENT-001',
        $password,
    );
    set_error_handler(static function (int $severity, string $message): bool {
        return $severity === E_WARNING
            && str_starts_with($message, 'Cannot modify header information');
    });

    try {
        $before = $counts();
        $request('GET', '/admin/bulk-import');
        $index = $controller->index();
        assertIntegration(
            http_response_code() === 200
            && str_contains($index, 'Importación masiva')
            && str_contains($index, 'DISPOSABLE_TEST_RELATIONSHIP'),
            'E015 Phase 7 MariaDB administrative page did not expose the safe active catalog help.'
        );
        $request('GET', '/admin/bulk-import/template');
        $template = $templateController->download();
        assertIntegration(
            http_response_code() === 200
            && $template !== ''
            && str_starts_with($template, 'PK'),
            'E015 Phase 7 MariaDB template download did not return the fixed XLSX artifact.'
        );

        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => 'invalid'], $upload($source));
        $invalidCsrf = $controller->preview();
        assertIntegration(
            http_response_code() === 403
            && str_contains($invalidCsrf, 'no pudo verificarse')
            && $counts() === $before
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB invalid Preview CSRF changed state or left a temporary file.'
        );

        $previewCsrf = $csrf->token();
        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => $previewCsrf], $upload($source));
        $previewHtml = $controller->preview();
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $previewHtml, $tokenMatch);
        preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $previewHtml, $csrfMatch);
        $token = $tokenMatch[1] ?? '';
        $applyCsrf = $csrfMatch[1] ?? '';
        $serverState = $session->get('_e015_bulk_import_preview');
        assertIntegration(
            http_response_code() === 200
            && strlen($token) === 64
            && strlen($applyCsrf) === 64
            && is_array($serverState)
            && ($serverState['token'] ?? null) === $token
            && ($serverState['actor_id'] ?? null) === 1
            && ($serverState['expires_at'] ?? 0) - ($serverState['issued_at'] ?? 0) === 900
            && ($serverState['digest'] ?? null) === hash_file('sha256', $source)
            && str_contains($previewHtml, 'Nuevo')
            && !str_contains($previewHtml, $password)
            && !str_contains($previewHtml, 'E015-P7-REP-001')
            && !str_contains($previewHtml, (string) hash_file('sha256', $source))
            && !str_contains($previewHtml, $source)
            && $counts() === $before
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB Preview was unsafe, stateful, non-expiring or left a temporary file.'
        );

        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $applyCsrf, 'preview_token' => $token],
            $upload($source),
        );
        assertIntegration(
            $applyController->apply() === ''
            && http_response_code() === 303
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB Apply did not use PRG or clean its exact reupload.'
        );
        $afterFirst = $counts();
        assertIntegration(
            $afterFirst['persons'] === $before['persons'] + 2
            && $afterFirst['representatives'] === $before['representatives'] + 1
            && $afterFirst['users'] === $before['users'] + 1
            && $afterFirst['students'] === $before['students'] + 1
            && $afterFirst['families'] === $before['families'] + 1
            && $afterFirst['family_representatives'] === $before['family_representatives'] + 1
            && $afterFirst['family_students'] === $before['family_students'] + 1,
            'E015 Phase 7 MariaDB Apply did not persist the exact Family Aggregate.'
        );
        $physical = $connection->query(
            "SELECT f.id AS family_id, r.id AS representative_id, u.id AS user_id, "
            . "u.password_hash, s.id AS student_id, fr.id AS representative_membership_id, "
            . "fs.id AS student_membership_id, DATE_FORMAT(fr.started_at, '%Y-%m-%d %H:%i:%s') AS representative_started_at, "
            . "DATE_FORMAT(fs.started_at, '%Y-%m-%d %H:%i:%s') AS student_started_at "
            . "FROM families f "
            . "INNER JOIN family_representatives fr ON fr.family_id = f.id AND fr.ended_at IS NULL "
            . "INNER JOIN representatives r ON r.id = fr.representative_id "
            . "INNER JOIN persons p ON p.id = r.person_id "
            . "INNER JOIN users u ON u.person_id = p.id "
            . "INNER JOIN family_students fs ON fs.family_id = f.id AND fs.ended_at IS NULL "
            . "INNER JOIN students s ON s.id = fs.student_id "
            . "WHERE f.family_code = 'F92700001'"
        )->fetch(PDO::FETCH_ASSOC);
        assertIntegration(
            $physical !== false
            && (int) $physical['family_id'] > 0
            && (int) $physical['representative_id'] > 0
            && (int) $physical['user_id'] > 0
            && (int) $physical['student_id'] > 0
            && (int) $physical['representative_membership_id'] > 0
            && (int) $physical['student_membership_id'] > 0
            && $physical['representative_started_at'] === '2026-08-01 00:00:00'
            && $physical['student_started_at'] === '2026-08-01 00:00:00'
            && $physical['password_hash'] !== $password
            && $hasher->verify($password, (string) $physical['password_hash']),
            'E015 Phase 7 MariaDB physical persistence AUTO_INCREMENT UTC or password hashing failed.'
        );
        $originalHash = (string) $physical['password_hash'];

        $request('GET', '/admin/bulk-import/result');
        $resultHtml = $controller->result();
        assertIntegration(
            http_response_code() === 200
            && str_contains($resultHtml, 'F92700001')
            && str_contains($resultHtml, 'Aplicado')
            && !str_contains($resultHtml, $password),
            'E015 Phase 7 MariaDB result did not expose only the safe Family outcome.'
        );
        $request('GET', '/admin/bulk-import/errors.csv');
        $csv = $controller->errorsCsv();
        assertIntegration(
            http_response_code() === 200
            && str_starts_with($csv, "category,sheet,row,field,message\r\n")
            && !str_contains($csv, $password),
            'E015 Phase 7 MariaDB CSV report was unavailable or unsafe.'
        );

        $replayBefore = $counts();
        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $csrf->token(), 'preview_token' => $token],
            $upload($source),
        );
        $applyController->apply();
        $request('GET', '/admin/bulk-import/result');
        $replayResult = $controller->result();
        assertIntegration(
            http_response_code() === 200
            && str_contains($replayResult, 'La vista previa ya no es válida')
            && $counts() === $replayBefore
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB consumed token was replayable or changed physical state.'
        );

        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => $csrf->token()], $upload($source));
        $idempotentPreview = $controller->preview();
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $idempotentPreview, $tokenMatch);
        preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $idempotentPreview, $csrfMatch);
        assertIntegration(
            str_contains($idempotentPreview, 'Ya existe')
            && strlen($tokenMatch[1] ?? '') === 64,
            'E015 Phase 7 MariaDB idempotent Preview did not classify ALREADY_EXISTS.'
        );
        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $csrfMatch[1] ?? '', 'preview_token' => $tokenMatch[1] ?? ''],
            $upload($source),
        );
        $applyController->apply();
        $request('GET', '/admin/bulk-import/result');
        $idempotentResult = $controller->result();
        $currentHash = (string) $connection->query(
            "SELECT u.password_hash FROM users u INNER JOIN persons p ON p.id = u.person_id "
            . "WHERE p.document_number = 'E015-P7-REP-001'"
        )->fetchColumn();
        assertIntegration(
            str_contains($idempotentResult, 'Sin cambios')
            && $counts() === $afterFirst
            && $currentHash === $originalHash
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB exact HTTP reimport duplicated rows or replaced the password.'
        );

        $conflictSource = $workbook(
            'phase7-conflict.xlsx',
            'F92700001',
            'Nombre incompatible',
            'E015-P7-REP-001',
            'E015-P7-STUDENT-001',
            $password,
        );
        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => $csrf->token()], $upload($conflictSource));
        $conflictPreview = $controller->preview();
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $conflictPreview, $tokenMatch);
        preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $conflictPreview, $csrfMatch);
        assertIntegration(
            str_contains($conflictPreview, 'Conflicto')
            && !str_contains($conflictPreview, $password)
            && strlen($tokenMatch[1] ?? '') === 64,
            'E015 Phase 7 MariaDB conflict Preview was missing unsafe or not applicable through Phase 6.'
        );
        $conflictBefore = $counts();
        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $csrfMatch[1] ?? '', 'preview_token' => $tokenMatch[1] ?? ''],
            $upload($conflictSource),
        );
        $applyController->apply();
        $request('GET', '/admin/bulk-import/result');
        $conflictResult = $controller->result();
        assertIntegration(
            str_contains($conflictResult, 'Conflicto')
            && $counts() === $conflictBefore
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB conflict Apply wrote partial state or exposed an unsafe result.'
        );

        $concurrentSource = $workbook(
            'phase7-concurrent.xlsx',
            'F92700002',
            'Familia concurrente',
            'E015-P7-REP-002',
            'E015-P7-STUDENT-002',
            'ClaveConcurrente9271!',
        );
        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => $csrf->token()], $upload($concurrentSource));
        $concurrentPreview = $controller->preview();
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $concurrentPreview, $tokenMatch);
        preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $concurrentPreview, $csrfMatch);
        assertIntegration(
            str_contains($concurrentPreview, 'Nuevo')
            && strlen($tokenMatch[1] ?? '') === 64,
            'E015 Phase 7 MariaDB concurrent-change fixture did not begin as NEW.'
        );
        $activeStatusId = (int) $connection->query(
            "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
            . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
        )->fetchColumn();
        $insertConcurrent = $connection->prepare(
            'INSERT INTO families (family_code, display_name, status_id) '
            . 'VALUES (:familyCode, :displayName, :statusId)'
        );
        $insertConcurrent->execute([
            ':familyCode' => 'F92700002',
            ':displayName' => 'Cambio concurrente',
            ':statusId' => $activeStatusId,
        ]);
        $concurrentFamilyId = (int) $connection->lastInsertId();
        $relationshipTypeId = (int) $connection->query(
            "SELECT id FROM relationship_types "
            . "WHERE code = 'DISPOSABLE_TEST_RELATIONSHIP'"
        )->fetchColumn();
        $insertConcurrentMembership = $connection->prepare(
            'INSERT INTO family_representatives '
            . '(family_id, representative_id, relationship_type_id, is_primary, started_at) '
            . 'VALUES (:familyId, :representativeId, :relationshipTypeId, TRUE, :startedAt)'
        );
        $insertConcurrentMembership->execute([
            ':familyId' => $concurrentFamilyId,
            ':representativeId' => (int) $physical['representative_id'],
            ':relationshipTypeId' => $relationshipTypeId,
            ':startedAt' => '2026-08-15 00:00:00',
        ]);
        $afterConcurrentWrite = $counts();
        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $csrfMatch[1] ?? '', 'preview_token' => $tokenMatch[1] ?? ''],
            $upload($concurrentSource),
        );
        $concurrentApplyResponse = $applyController->apply();
        $concurrentApplyStatus = http_response_code();
        $concurrentStoredResult = $session->get('_e015_bulk_import_result');
        $request('GET', '/admin/bulk-import/result');
        $concurrentResult = $controller->result();
        $concurrentCounts = $counts();
        $concurrentPersonCount = (int) $connection->query(
            "SELECT COUNT(*) FROM persons WHERE document_number = 'E015-P7-REP-002'"
        )->fetchColumn();
        $concurrentTemporaryCount = $temporaryCount();
        assertIntegration(
            str_contains($concurrentResult, 'Conflicto')
            && $concurrentCounts === $afterConcurrentWrite
            && $concurrentPersonCount === 0
            && $concurrentTemporaryCount === 0,
            sprintf(
                'E015 Phase 7 MariaDB concurrent-change mismatch: safe_conflict=%s; counts_equal=%s; '
                . 'representative_person_count=%d; temporary_count=%d; result=%s',
                str_contains($concurrentResult, 'Conflicto') ? 'true' : 'false',
                $concurrentCounts === $afterConcurrentWrite ? 'true' : 'false',
                $concurrentPersonCount,
                $concurrentTemporaryCount,
                diagnosticValue(sprintf(
                    'apply_status=%d; apply_response=%s; state=%s; html_tail=%s',
                    $concurrentApplyStatus,
                    $concurrentApplyResponse,
                    json_encode($concurrentStoredResult, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    substr((string) preg_replace('/\s+/', ' ', trim(strip_tags($concurrentResult))), -1000),
                )),
            )
        );

        $digestSource = $workbook(
            'phase7-digest.xlsx',
            'F92700003',
            'Familia digest',
            'E015-P7-REP-003',
            'E015-P7-STUDENT-003',
            'ClaveDigest9271!',
        );
        $request('POST', '/admin/bulk-import/preview', ['_csrf_token' => $csrf->token()], $upload($digestSource));
        $digestPreview = $controller->preview();
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $digestPreview, $tokenMatch);
        preg_match('/name="_csrf_token" value="([a-f0-9]{64})"/', $digestPreview, $csrfMatch);
        $digestBefore = $counts();
        $request(
            'POST',
            '/admin/bulk-import/apply',
            ['_csrf_token' => $csrfMatch[1] ?? '', 'preview_token' => $tokenMatch[1] ?? ''],
            $upload($source),
        );
        $applyController->apply();
        $request('GET', '/admin/bulk-import/result');
        $digestResult = $controller->result();
        assertIntegration(
            str_contains($digestResult, 'El archivo no coincide')
            && $counts() === $digestBefore
            && $temporaryCount() === 0,
            'E015 Phase 7 MariaDB digest mismatch wrote state or retained the reupload.'
        );
    } finally {
        restore_error_handler();
        foreach (glob($temporaryDirectory . '/*.xlsx') ?: [] as $temporaryPath) {
            @unlink($temporaryPath);
        }
        if (is_dir($temporaryDirectory)) {
            @rmdir($temporaryDirectory);
        }
        unset($fixtureFactory);
        gc_collect_cycles();
    }
}

$requiredNonEmptyEnvironment = [
    'E0041_DB_HOST',
    'E0041_DB_PORT',
    'E0041_DB_USERNAME',
    'E0041_DB_PREFIX',
];

$environment = [];
foreach ($requiredNonEmptyEnvironment as $environmentName) {
    $environmentValue = getenv($environmentName);
    if ($environmentValue === false || trim($environmentValue) === '') {
        throw new RuntimeException(
            sprintf('%s must be explicitly defined and non-empty; .env fallback is intentionally forbidden.', $environmentName)
        );
    }

    $environment[$environmentName] = $environmentValue;
}

$passwordValue = getenv('E0041_DB_PASSWORD');
if ($passwordValue === false) {
    throw new RuntimeException(
        'E0041_DB_PASSWORD must be explicitly defined; an empty value is allowed and .env fallback is intentionally forbidden.'
    );
}
$environment['E0041_DB_PASSWORD'] = $passwordValue;

if (getenv('E0041_DB_ALLOW_DISPOSABLE') !== '1') {
    throw new RuntimeException(
        'E0041_DB_ALLOW_DISPOSABLE=1 is required to authorize disposable databases.'
    );
}

$host = $environment['E0041_DB_HOST'];
$port = (int) $environment['E0041_DB_PORT'];
$username = $environment['E0041_DB_USERNAME'];
$password = $environment['E0041_DB_PASSWORD'];
$databasePrefix = $environment['E0041_DB_PREFIX'];
$charset = 'utf8mb4';

assertIntegration(
    preg_match('/^[a-z][a-z0-9_]{2,30}$/', $databasePrefix) === 1,
    'E0041_DB_PREFIX must be a safe lowercase disposable prefix.'
);
foreach ([$host, $username, $password, $databasePrefix] as $environmentValue) {
    assertIntegration(
        !str_contains(strtolower($environmentValue), 'ueant'),
        'UEAnt is explicitly forbidden in every E004.1 integration-test environment value.'
    );
}

echo sprintf(
    "MariaDB disposable target: host=%s port=%d prefix=%s\n",
    $host,
    $port,
    $databasePrefix
);

$suffix = bin2hex(random_bytes(5));
$identityDatabase = $databasePrefix . '_identity_' . $suffix;
$familyCodeUpgradeDatabase = $databasePrefix . '_code_upgrade_' . $suffix;
$familyCodeRangeDatabase = $databasePrefix . '_code_range_' . $suffix;
$cleanupProbePrefix = $databasePrefix . '_cleanup_' . $suffix;
$cleanupProbeDatabase = $cleanupProbePrefix . '_first';

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$createdDatabases = [];
try {
    $cleanupProbeCreated = [];
    try {
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $cleanupProbeDatabase
        ));
        $cleanupProbeCreated[] = $cleanupProbeDatabase;
        throw new RuntimeException('Intentional failure after the first disposable database creation.');
    } catch (RuntimeException $exception) {
        assertIntegration(
            $exception->getMessage() === 'Intentional failure after the first disposable database creation.',
            'Partial database creation failed for an unexpected reason.'
        );
    } finally {
        $cleanupFailures = dropDisposableDatabases($server, $cleanupProbeCreated);
        assertIntegration(
            $cleanupFailures === [],
            'Partial-creation cleanup failed: ' . implode('; ', $cleanupFailures)
        );
    }

    $cleanupProbeStatement = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE LOCATE(:prefix, schema_name) = 1'
    );
    $cleanupProbeStatement->execute([':prefix' => $cleanupProbePrefix]);
    assertIntegration(
        (int) $cleanupProbeStatement->fetchColumn() === 0,
        'Partial-creation cleanup left a disposable database behind.'
    );

    foreach ([$identityDatabase, $familyCodeUpgradeDatabase, $familyCodeRangeDatabase] as $database) {
        assertIntegration(
            preg_match('/^[a-z][a-z0-9_]+$/', $database) === 1 && strlen($database) <= 64,
            'Unsafe disposable database name.'
        );
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database
        ));
        $createdDatabases[] = $database;
    }

    $identity = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $identityDatabase, $charset),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $identity->exec("SET time_zone = '+00:00'");
    $mariaDbVersion = (string) $identity->query('SELECT VERSION()')->fetchColumn();
    assertIntegration(
        str_contains(strtolower($mariaDbVersion), 'mariadb')
        && preg_match('/^10\.4\./', $mariaDbVersion) === 1,
        'Physical baseline requires MariaDB 10.4; observed version: ' . $mariaDbVersion
    );
    assertIntegration(
        $identity->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00',
        'MariaDB harness inspection session did not establish the UTC SQL convention.'
    );
    $databaseConfig = new DatabaseConfig([
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'database' => $identityDatabase,
        'username' => $username,
        'password' => $password,
        'charset' => $charset,
    ]);
    $managerA = new ConnectionManager(new ConnectionFactory(), $databaseConfig);
    (new MigrationRunner($managerA))->run();
    $connectionA = $managerA->connection();

    $managerForDatabase = static function (string $database) use (
        $host,
        $port,
        $username,
        $password,
        $charset,
    ): ConnectionManager {
        return new ConnectionManager(new ConnectionFactory(), new DatabaseConfig([
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ]));
    };
    $familyCodeUpgradeManager = $managerForDatabase($familyCodeUpgradeDatabase);
    runMariaDbMigrationsThrough010($familyCodeUpgradeManager->connection());
    runMariaDbFamilyCodeLegacyUpgradeScenario($familyCodeUpgradeManager->connection());
    $familyCodeRangeManager = $managerForDatabase($familyCodeRangeDatabase);
    runMariaDbMigrationsThrough010($familyCodeRangeManager->connection());
    runMariaDbFamilyCodeRangeGuardScenario($familyCodeRangeManager->connection());

    runMariaDbSubmissionSnapshotRemovalMigrationScenario($identity);

    $sessionCollation = $connectionA->query(
        'SELECT @@character_set_connection AS character_set_connection, '
        . '@@collation_connection AS collation_connection'
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $sessionCollation !== false
        && $sessionCollation['character_set_connection'] === 'utf8mb4'
        && $sessionCollation['collation_connection'] === 'utf8mb4_unicode_ci',
        'ConnectionFactory did not establish the approved MariaDB charset and collation. '
        . mariaDbPersonCollationDiagnostics($connectionA)
    );

    $actualTables = $identity->query(
        'SELECT table_name FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    sort($actualTables, SORT_STRING);
    $expectedTables = expectedBaselineTables();
    assertIntegration(
        $actualTables === $expectedTables,
        schemaInventoryDifferenceMessage($expectedTables, $actualTables)
    );
    $physicalForeignKeyCount = (int) $identity->query(
        'SELECT COUNT(*) FROM information_schema.referential_constraints '
        . 'WHERE constraint_schema = DATABASE()'
    )->fetchColumn();
    assertIntegration(
        $physicalForeignKeyCount === 53,
        sprintf('Expected 53 physical foreign keys; MariaDB reported %d.', $physicalForeignKeyCount)
    );
    assertMariaDbFamilyCodeSchema($identity);

    $familyResourceTables = [
        'authorized_pickup_assignments',
        'emergency_contact_assignments',
        'family_addresses',
        'family_authorized_pickups',
        'family_emergency_contacts',
        'representative_address_assignments',
        'student_address_assignments',
    ];
    $familyResourceAutoIncrement = $identity->query(
        "SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND column_name = 'id' AND extra = 'auto_increment' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        array_values(array_intersect($familyResourceAutoIncrement, $familyResourceTables)) === $familyResourceTables,
        'MariaDB Family resource tables do not all expose database-managed AUTO_INCREMENT identity.'
    );

    $familyAddressColumns = $identity->query(
        "SELECT column_name FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'family_addresses' ORDER BY ordinal_position"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        $familyAddressColumns === [
            'id', 'family_id', 'label', 'main_street', 'street_number', 'secondary_street',
            'sector', 'reference', 'latitude', 'longitude', 'status_id', 'created_at', 'updated_at',
        ],
        'MariaDB FamilyAddress columns differ from the simplified baseline: '
        . implode(', ', $familyAddressColumns)
    );

    $familyAddressForeignKeys = $identity->query(
        "SELECT constraint_name FROM information_schema.referential_constraints "
        . "WHERE constraint_schema = DATABASE() AND table_name = 'family_addresses' ORDER BY constraint_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        $familyAddressForeignKeys === ['fk_family_addresses_family', 'fk_family_addresses_status'],
        'MariaDB FamilyAddress foreign keys differ from the simplified baseline: '
        . implode(', ', $familyAddressForeignKeys)
    );

    $familyAddressIndexes = $identity->query(
        "SELECT DISTINCT index_name FROM information_schema.statistics "
        . "WHERE table_schema = DATABASE() AND table_name = 'family_addresses' ORDER BY index_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        in_array('uq_family_addresses_id_family', $familyAddressIndexes, true)
        && in_array('idx_family_addresses_family_status', $familyAddressIndexes, true)
        && array_intersect([
            'idx_family_addresses_province',
            'idx_family_addresses_canton_province',
            'idx_family_addresses_parish_canton_province',
        ], $familyAddressIndexes) === [],
        'MariaDB FamilyAddress indexes do not preserve the simplified baseline: '
        . implode(', ', $familyAddressIndexes)
    );

    $familyAddressChecks = $identity->query(
        "SELECT constraint_name FROM information_schema.table_constraints "
        . "WHERE constraint_schema = DATABASE() AND table_name = 'family_addresses' "
        . "AND constraint_type = 'CHECK' ORDER BY constraint_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    assertIntegration(
        $familyAddressChecks === [
            'chk_family_addresses_coordinates_pair',
            'chk_family_addresses_latitude',
            'chk_family_addresses_longitude',
        ],
        'MariaDB FamilyAddress coordinate checks differ from the simplified baseline: '
        . implode(', ', $familyAddressChecks)
    );

    $acknowledgementColumns = [];
    foreach ([
        'acknowledgement_requirements',
        'representative_acknowledgement_completions',
        'representative_acknowledgements',
    ] as $acknowledgementTable) {
        $statement = $identity->prepare(
            'SELECT column_name, column_type, is_nullable, extra FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ordinal_position'
        );
        $statement->execute([':table' => $acknowledgementTable]);
        $acknowledgementColumns[$acknowledgementTable] = $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    assertIntegration(
        array_column($acknowledgementColumns['acknowledgement_requirements'], 'column_name') === [
            'id', 'academic_period_id', 'title', 'url', 'official_reference',
            'status_id', 'created_at', 'updated_at',
        ],
        'MariaDB AcknowledgementRequirement columns differ from ADR-0022.'
    );
    assertIntegration(
        array_column($acknowledgementColumns['representative_acknowledgement_completions'], 'column_name') === [
            'id', 'representative_id', 'academic_period_id', 'completed_at',
        ],
        'MariaDB RepresentativeAcknowledgementCompletion columns differ from ADR-0022.'
    );
    assertIntegration(
        array_column($acknowledgementColumns['representative_acknowledgements'], 'column_name') === [
            'id', 'representative_acknowledgement_completion_id',
            'acknowledgement_requirement_id', 'academic_period_id',
        ],
        'MariaDB RepresentativeAcknowledgement columns differ from ADR-0022.'
    );

    $requirementColumnMetadata = [];
    foreach ($acknowledgementColumns['acknowledgement_requirements'] as $column) {
        $requirementColumnMetadata[$column['column_name']] = $column;
    }
    assertIntegration(
        $requirementColumnMetadata['title']['column_type'] === 'varchar(200)'
        && $requirementColumnMetadata['title']['is_nullable'] === 'NO'
        && $requirementColumnMetadata['url']['column_type'] === 'varchar(500)'
        && $requirementColumnMetadata['url']['is_nullable'] === 'NO'
        && $requirementColumnMetadata['official_reference']['column_type'] === 'varchar(255)'
        && $requirementColumnMetadata['official_reference']['is_nullable'] === 'YES',
        'MariaDB acknowledgement text lengths or nullability differ from ADR-0022.'
    );

    $acknowledgementAutoIncrement = $identity->query(
        "SELECT table_name FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND table_name IN ('acknowledgement_requirements', "
        . "'representative_acknowledgement_completions', 'representative_acknowledgements') "
        . "AND column_name = 'id' AND extra = 'auto_increment' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedAcknowledgementAutoIncrement = [
        'acknowledgement_requirements',
        'representative_acknowledgement_completions',
        'representative_acknowledgements',
    ];
    sort($acknowledgementAutoIncrement, SORT_STRING);
    sort($expectedAcknowledgementAutoIncrement, SORT_STRING);
    assertIntegration(
        $acknowledgementAutoIncrement === $expectedAcknowledgementAutoIncrement,
        'MariaDB Institutional Acknowledgements identities are not all AUTO_INCREMENT.'
    );

    $acknowledgementForeignKeys = $identity->query(
        "SELECT constraint_name FROM information_schema.referential_constraints "
        . "WHERE constraint_schema = DATABASE() AND table_name IN ("
        . "'acknowledgement_requirements', 'representative_acknowledgement_completions', "
        . "'representative_acknowledgements') ORDER BY constraint_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expectedAcknowledgementForeignKeys = [
        'fk_ack_completions_period',
        'fk_ack_completions_representative',
        'fk_ack_requirements_period',
        'fk_ack_requirements_status',
        'fk_acknowledgements_completion_period',
        'fk_acknowledgements_requirement_period',
    ];
    sort($acknowledgementForeignKeys, SORT_STRING);
    sort($expectedAcknowledgementForeignKeys, SORT_STRING);
    assertIntegration(
        $acknowledgementForeignKeys === $expectedAcknowledgementForeignKeys,
        'MariaDB Institutional Acknowledgements foreign keys differ from ADR-0022: '
        . implode(', ', $acknowledgementForeignKeys)
    );

    foreach ([
        'institutional_documents',
        'institutional_document_versions',
        'document_requirements',
        'enrollment_document_acceptances',
    ] as $retiredTable) {
        assertIntegration(
            !in_array($retiredTable, $actualTables, true),
            sprintf('Retired MariaDB document/version acceptance table remains: %s.', $retiredTable)
        );
    }

    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 11, 'Not all baseline migrations were recorded.');
    assertIntegration(
        (int) $identity->query(
            "SELECT COUNT(*) FROM migrations WHERE migration = '010_remove_submission_snapshots'"
        )->fetchColumn() === 1,
        'MariaDB migration 010 was not recorded exactly once.'
    );
    assertIntegration(
        (int) $identity->query(
            "SELECT COUNT(*) FROM migrations WHERE migration = '011_add_family_code_to_families'"
        )->fetchColumn() === 1,
        'MariaDB migration 011 was not recorded exactly once.'
    );
    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM status_types')->fetchColumn() === 3, 'Status type baseline is incomplete.');
    assertIntegration((int) $identity->query('SELECT COUNT(*) FROM statuses')->fetchColumn() === 8, 'Status baseline is incomplete.');

    $identity->exec(
        "INSERT INTO document_types (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO document_types (id, code, name, is_active) "
        . "VALUES (2, 'INACTIVE_TEST', 'Inactive disposable test value', FALSE)"
    );
    $identity->exec(
        "INSERT INTO sexes (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO sexes (id, code, name, is_active) "
        . "VALUES (2, 'INACTIVE_TEST', 'Inactive disposable test value', FALSE)"
    );
    $identity->exec(
        "INSERT INTO marital_statuses (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $identity->exec(
        "INSERT INTO education_levels (id, code, name, is_active) "
        . "VALUES (1, 'TEST', 'Disposable test value', TRUE)"
    );
    $generalStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    $inactiveGeneralStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'INACTIVE'"
    )->fetchColumn();
    $disabledUserStatusId = (int) $identity->query(
        "SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id "
        . "WHERE st.code = 'USER_STATUS' AND s.code = 'DISABLED'"
    )->fetchColumn();
    assertMariaDbStatementRejected(
        $identity,
        'INSERT INTO families (family_code, display_name, status_id) '
            . 'VALUES (NULL, :displayName, :statusId)',
        [':displayName' => 'Null FamilyCode Probe', ':statusId' => $generalStatusId],
        'MariaDB families.family_code did not enforce NOT NULL physically.'
    );

    $identity->beginTransaction();
    try {
        $insertPeriod = $identity->prepare(
            'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
            . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
        );
        $insertPeriod->execute([
            ':code' => 'E009_ACK_PERIOD',
            ':name' => 'E009 acknowledgement period',
            ':startsOn' => '2026-08-01',
            ':endsOn' => '2027-07-31',
            ':statusId' => $generalStatusId,
        ]);
        $acknowledgementPeriodId = (int) $identity->lastInsertId();
        $insertPeriod->execute([
            ':code' => 'E009_ACK_SECOND_PERIOD',
            ':name' => 'E009 second acknowledgement period',
            ':startsOn' => '2027-08-01',
            ':endsOn' => '2028-07-31',
            ':statusId' => $generalStatusId,
        ]);
        $secondAcknowledgementPeriodId = (int) $identity->lastInsertId();
        $insertPeriod->execute([
            ':code' => 'E009_ACK_ZERO_PERIOD',
            ':name' => 'E009 zero requirement period',
            ':startsOn' => '2028-08-01',
            ':endsOn' => '2029-07-31',
            ':statusId' => $generalStatusId,
        ]);
        $zeroRequirementPeriodId = (int) $identity->lastInsertId();

        assertIntegration(
            $acknowledgementPeriodId > 0
            && $secondAcknowledgementPeriodId > 0
            && $zeroRequirementPeriodId > 0
            && count(array_unique([
                $acknowledgementPeriodId,
                $secondAcknowledgementPeriodId,
                $zeroRequirementPeriodId,
            ])) === 3,
            'MariaDB did not generate distinct positive AcademicPeriod identities for E009.'
        );

        $zeroRequirementStatement = $identity->prepare(
            'SELECT (SELECT COUNT(*) FROM acknowledgement_requirements '
            . 'WHERE academic_period_id = :requirementPeriodId) '
            . '+ (SELECT COUNT(*) FROM representative_acknowledgement_completions '
            . 'WHERE academic_period_id = :completionPeriodId)'
        );
        $zeroRequirementStatement->execute([
            ':requirementPeriodId' => $zeroRequirementPeriodId,
            ':completionPeriodId' => $zeroRequirementPeriodId,
        ]);
        assertIntegration(
            (int) $zeroRequirementStatement->fetchColumn() === 0,
            'MariaDB did not permit an AcademicPeriod with zero requirements and no artificial Completion.'
        );

        $insertPerson = $identity->prepare(
            'INSERT INTO persons '
            . '(first_name, first_surname, birth_date, sex_id, email, status_id) '
            . 'VALUES (:firstName, :firstSurname, :birthDate, :sexId, :email, :statusId)'
        );
        $insertPerson->execute([
            ':firstName' => 'E009',
            ':firstSurname' => 'Representative',
            ':birthDate' => '1980-01-01',
            ':sexId' => 1,
            ':email' => 'e009-representative@example.test',
            ':statusId' => $generalStatusId,
        ]);
        $acknowledgementPersonId = (int) $identity->lastInsertId();
        $insertRepresentative = $identity->prepare(
            'INSERT INTO representatives (person_id, status_id) VALUES (:personId, :statusId)'
        );
        $insertRepresentative->execute([
            ':personId' => $acknowledgementPersonId,
            ':statusId' => $generalStatusId,
        ]);
        $acknowledgementRepresentativeId = (int) $identity->lastInsertId();
        assertIntegration(
            $acknowledgementPersonId > 0 && $acknowledgementRepresentativeId > 0,
            'MariaDB did not generate the E009 Representative prerequisite identities.'
        );

        $insertRequirement = $identity->prepare(
            'INSERT INTO acknowledgement_requirements '
            . '(academic_period_id, title, url, official_reference, status_id) '
            . 'VALUES (:periodId, :title, :url, :officialReference, :statusId)'
        );
        $insertRequirement->execute([
            ':periodId' => $acknowledgementPeriodId,
            ':title' => 'External institutional content A',
            ':url' => 'https://example.test/institutional-content/a',
            ':officialReference' => 'REF-E009-A',
            ':statusId' => $generalStatusId,
        ]);
        $requirementAId = (int) $identity->lastInsertId();
        $insertRequirement->execute([
            ':periodId' => $acknowledgementPeriodId,
            ':title' => 'External institutional content B',
            ':url' => 'https://example.test/institutional-content/b',
            ':officialReference' => null,
            ':statusId' => $generalStatusId,
        ]);
        $requirementBId = (int) $identity->lastInsertId();
        $insertRequirement->execute([
            ':periodId' => $secondAcknowledgementPeriodId,
            ':title' => 'External institutional content C',
            ':url' => 'https://example.test/institutional-content/c',
            ':officialReference' => null,
            ':statusId' => $generalStatusId,
        ]);
        $secondPeriodRequirementId = (int) $identity->lastInsertId();
        assertIntegration(
            $requirementAId > 0
            && $requirementBId > 0
            && $secondPeriodRequirementId > 0
            && count(array_unique([$requirementAId, $requirementBId, $secondPeriodRequirementId])) === 3,
            'MariaDB did not generate distinct positive AcknowledgementRequirement identities.'
        );

        $requirementStatus = $identity->prepare(
            'SELECT st.code AS status_type_code, s.code AS status_code '
            . 'FROM acknowledgement_requirements ar '
            . 'INNER JOIN statuses s ON s.id = ar.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id WHERE ar.id = :id'
        );
        $requirementStatus->execute([':id' => $requirementAId]);
        assertIntegration(
            $requirementStatus->fetch(PDO::FETCH_ASSOC) === [
                'status_type_code' => 'GENERAL_STATUS',
                'status_code' => 'ACTIVE',
            ],
            'MariaDB AcknowledgementRequirement did not resolve exact GENERAL_STATUS ACTIVE.'
        );

        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO acknowledgement_requirements '
            . '(academic_period_id, title, url, official_reference, status_id) '
            . 'VALUES (:periodId, :title, :url, NULL, :statusId)',
            [
                ':periodId' => $acknowledgementPeriodId,
                ':title' => '   ',
                ':url' => 'https://example.test/invalid-title',
                ':statusId' => $generalStatusId,
            ],
            'MariaDB accepted a blank AcknowledgementRequirement title.'
        );
        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO acknowledgement_requirements '
            . '(academic_period_id, title, url, official_reference, status_id) '
            . 'VALUES (:periodId, :title, :url, NULL, :statusId)',
            [
                ':periodId' => $acknowledgementPeriodId,
                ':title' => 'Invalid blank URL',
                ':url' => '   ',
                ':statusId' => $generalStatusId,
            ],
            'MariaDB accepted a blank AcknowledgementRequirement URL.'
        );
        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO acknowledgement_requirements '
            . '(academic_period_id, title, url, official_reference, status_id) '
            . 'VALUES (:periodId, :title, :url, :officialReference, :statusId)',
            [
                ':periodId' => $acknowledgementPeriodId,
                ':title' => 'Invalid blank official reference',
                ':url' => 'https://example.test/invalid-reference',
                ':officialReference' => '   ',
                ':statusId' => $generalStatusId,
            ],
            'MariaDB accepted a blank non-null OfficialReference.'
        );
        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO acknowledgement_requirements '
            . '(academic_period_id, title, url, official_reference, status_id) '
            . 'VALUES (:periodId, :title, :url, NULL, :statusId)',
            [
                ':periodId' => $acknowledgementPeriodId,
                ':title' => 'Invalid missing status',
                ':url' => 'https://example.test/invalid-status',
                ':statusId' => 999999999,
            ],
            'MariaDB accepted a missing AcknowledgementRequirement status.'
        );

        $insertCompletion = $identity->prepare(
            'INSERT INTO representative_acknowledgement_completions '
            . '(representative_id, academic_period_id, completed_at) '
            . 'VALUES (:representativeId, :periodId, :completedAt)'
        );
        $insertCompletion->execute([
            ':representativeId' => $acknowledgementRepresentativeId,
            ':periodId' => $acknowledgementPeriodId,
            ':completedAt' => '2026-08-13 14:15:16',
        ]);
        $completionId = (int) $identity->lastInsertId();
        $insertCompletion->execute([
            ':representativeId' => $acknowledgementRepresentativeId,
            ':periodId' => $secondAcknowledgementPeriodId,
            ':completedAt' => '2027-08-13 14:15:16',
        ]);
        $secondCompletionId = (int) $identity->lastInsertId();
        assertIntegration(
            $completionId > 0 && $secondCompletionId > 0 && $completionId !== $secondCompletionId,
            'MariaDB did not generate distinct positive Completion identities.'
        );

        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO representative_acknowledgement_completions '
            . '(representative_id, academic_period_id, completed_at) '
            . 'VALUES (:representativeId, :periodId, :completedAt)',
            [
                ':representativeId' => $acknowledgementRepresentativeId,
                ':periodId' => $acknowledgementPeriodId,
                ':completedAt' => '2026-08-13 14:15:17',
            ],
            'MariaDB accepted a duplicate Completion for Representative + AcademicPeriod.'
        );

        $insertAcknowledgement = $identity->prepare(
            'INSERT INTO representative_acknowledgements '
            . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
            . 'VALUES (:completionId, :requirementId, :periodId)'
        );
        $insertAcknowledgement->execute([
            ':completionId' => $completionId,
            ':requirementId' => $requirementAId,
            ':periodId' => $acknowledgementPeriodId,
        ]);
        $acknowledgementAId = (int) $identity->lastInsertId();
        $insertAcknowledgement->execute([
            ':completionId' => $completionId,
            ':requirementId' => $requirementBId,
            ':periodId' => $acknowledgementPeriodId,
        ]);
        $acknowledgementBId = (int) $identity->lastInsertId();
        assertIntegration(
            $acknowledgementAId > 0
            && $acknowledgementBId > 0
            && $acknowledgementAId !== $acknowledgementBId,
            'MariaDB did not generate distinct positive RepresentativeAcknowledgement identities.'
        );

        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO representative_acknowledgements '
            . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
            . 'VALUES (:completionId, :requirementId, :periodId)',
            [
                ':completionId' => $completionId,
                ':requirementId' => $requirementAId,
                ':periodId' => $acknowledgementPeriodId,
            ],
            'MariaDB accepted the same Requirement twice within one Completion.'
        );
        assertMariaDbStatementRejected(
            $identity,
            'INSERT INTO representative_acknowledgements '
            . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
            . 'VALUES (:completionId, :requirementId, :periodId)',
            [
                ':completionId' => $secondCompletionId,
                ':requirementId' => $requirementAId,
                ':periodId' => $secondAcknowledgementPeriodId,
            ],
            'MariaDB accepted a Requirement from another AcademicPeriod into a Completion.'
        );

        $completedAt = $identity->prepare(
            'SELECT completed_at FROM representative_acknowledgement_completions WHERE id = :id'
        );
        $completedAt->execute([':id' => $completionId]);
        assertIntegration(
            $completedAt->fetchColumn() === '2026-08-13 14:15:16',
            'MariaDB did not preserve RepresentativeAcknowledgementCompletion UTC seconds.'
        );
    } finally {
        $identity->rollBack();
    }

    assertIntegration(
        (int) $identity->query(
            "SELECT COUNT(*) FROM academic_periods WHERE code LIKE 'E009_ACK_%'"
        )->fetchColumn() === 0
        && (int) $identity->query(
            "SELECT COUNT(*) FROM persons WHERE email = 'e009-representative@example.test'"
        )->fetchColumn() === 0
        && (int) $identity->query('SELECT COUNT(*) FROM acknowledgement_requirements')->fetchColumn() === 0
        && (int) $identity->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn() === 0
        && (int) $identity->query('SELECT COUNT(*) FROM representative_acknowledgements')->fetchColumn() === 0,
        'MariaDB Institutional Acknowledgements rollback left physical rows behind.'
    );

    $requirementRepository = new PdoAcknowledgementRequirementRepository($managerA);
    $completionRepository = new PdoRepresentativeAcknowledgementCompletionRepository($managerA);
    $insertPersistencePeriod = $connectionA->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    $insertPersistencePeriod->execute([
        ':code' => 'E009_PERSIST_PERIOD',
        ':name' => 'E009 persistence period',
        ':startsOn' => '2029-08-01',
        ':endsOn' => '2030-07-31',
        ':statusId' => $generalStatusId,
    ]);
    $persistencePeriodId = (int) $connectionA->lastInsertId();
    $insertPersistencePeriod->execute([
        ':code' => 'E009_PERSIST_OTHER',
        ':name' => 'E009 persistence other period',
        ':startsOn' => '2030-08-01',
        ':endsOn' => '2031-07-31',
        ':statusId' => $generalStatusId,
    ]);
    $persistenceOtherPeriodId = (int) $connectionA->lastInsertId();
    $insertPersistencePeriod->execute([
        ':code' => 'E009_PERSIST_ROLLBACK',
        ':name' => 'E009 persistence rollback period',
        ':startsOn' => '2031-08-01',
        ':endsOn' => '2032-07-31',
        ':statusId' => $generalStatusId,
    ]);
    $persistenceRollbackPeriodId = (int) $connectionA->lastInsertId();

    $insertPersistencePerson = $connectionA->prepare(
        'INSERT INTO persons (first_name, first_surname, birth_date, sex_id, email, status_id) '
        . 'VALUES (:firstName, :firstSurname, :birthDate, :sexId, :email, :statusId)'
    );
    $insertPersistenceRepresentative = $connectionA->prepare(
        'INSERT INTO representatives (person_id, status_id) VALUES (:personId, :statusId)'
    );
    $persistenceRepresentativeIds = [];
    foreach (['primary', 'rollback'] as $suffixName) {
        $insertPersistencePerson->execute([
            ':firstName' => 'E009',
            ':firstSurname' => 'Persistence ' . $suffixName,
            ':birthDate' => '1980-01-01',
            ':sexId' => 1,
            ':email' => 'e009-persistence-' . $suffixName . '@example.test',
            ':statusId' => $generalStatusId,
        ]);
        $personId = (int) $connectionA->lastInsertId();
        $insertPersistenceRepresentative->execute([
            ':personId' => $personId,
            ':statusId' => $generalStatusId,
        ]);
        $persistenceRepresentativeIds[$suffixName] = (int) $connectionA->lastInsertId();
    }

    assertIntegration(
        $persistencePeriodId > 0
        && $persistenceOtherPeriodId > 0
        && $persistenceRollbackPeriodId > 0
        && $persistenceRepresentativeIds['primary'] > 0
        && $persistenceRepresentativeIds['rollback'] > 0,
        'MariaDB did not create E009 Persistence prerequisite identities.'
    );

    $requirementA = $requirementRepository->save(
        \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
            new AcknowledgementAcademicPeriodId($persistencePeriodId),
            new AcknowledgementRequirementTitle('Política institucional ñ'),
            new AcknowledgementRequirementUrl('https://example.test/e009/policy-a'),
            new AcknowledgementOfficialReference('RES-E009-Ñ'),
            AcknowledgementRequirementStatus::Active,
        )
    );
    $requirementB = $requirementRepository->save(
        \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
            new AcknowledgementAcademicPeriodId($persistencePeriodId),
            new AcknowledgementRequirementTitle('Institutional content B'),
            new AcknowledgementRequirementUrl('relative/content-b'),
            null,
            AcknowledgementRequirementStatus::Active,
        )
    );
    $inactiveRequirement = $requirementRepository->save(
        \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
            new AcknowledgementAcademicPeriodId($persistencePeriodId),
            new AcknowledgementRequirementTitle('Inactive institutional content'),
            new AcknowledgementRequirementUrl('relative/inactive'),
            null,
            AcknowledgementRequirementStatus::Inactive,
        )
    );

    assertIntegration(
        ($requirementA->id()?->value() ?? 0) > 0
        && ($requirementB->id()?->value() ?? 0) > 0
        && ($inactiveRequirement->id()?->value() ?? 0) > 0
        && count(array_unique([
            $requirementA->id()?->value(),
            $requirementB->id()?->value(),
            $inactiveRequirement->id()?->value(),
        ])) === 3,
        'MariaDB E009 repositories did not return distinct generated Requirement identities.'
    );
    assertIntegration(
        $requirementA->title()->value() === 'Política institucional ñ'
        && $requirementA->officialReference()?->value() === 'RES-E009-Ñ'
        && $requirementB->officialReference() === null
        && $requirementA->isActive()
        && !$inactiveRequirement->isActive(),
        'MariaDB E009 Requirement repository did not roundtrip UTF-8 nullability and GENERAL_STATUS.'
    );

    $periodRequirements = $requirementRepository->findByAcademicPeriodId(
        new AcknowledgementAcademicPeriodId($persistencePeriodId)
    );
    $periodRequirementIds = array_map(
        static fn ($requirement): ?int => $requirement->id()?->value(),
        $periodRequirements,
    );
    $sortedPeriodRequirementIds = $periodRequirementIds;
    sort($sortedPeriodRequirementIds, SORT_NUMERIC);
    assertIntegration(
        count($periodRequirements) === 3
        && $periodRequirementIds === $sortedPeriodRequirementIds,
        'MariaDB E009 Requirement list is incomplete or not ordered by identity.'
    );
    assertIntegration(
        !$requirementRepository->hasAcknowledgements($requirementA->id()),
        'MariaDB E009 Requirement reported history before a Completion existed.'
    );

    $requirementA->update(
        new AcknowledgementRequirementTitle('Updated política institucional ñ'),
        new AcknowledgementRequirementUrl('https://example.test/e009/policy-a-updated'),
        new AcknowledgementOfficialReference('RES-E009-UPDATED'),
        false,
    );
    $updatedRequirementA = $requirementRepository->save($requirementA);
    assertIntegration(
        $updatedRequirementA->id()?->equals($requirementA->id()) === true
        && $updatedRequirementA->academicPeriodId()->value() === $persistencePeriodId
        && $updatedRequirementA->title()->value() === 'Updated política institucional ñ'
        && $updatedRequirementA->url()->value() === 'https://example.test/e009/policy-a-updated'
        && $updatedRequirementA->officialReference()?->value() === 'RES-E009-UPDATED',
        'MariaDB E009 Requirement repository did not update exact mutable state.'
    );

    $completedAt = new DateTimeImmutable('2030-02-03 10:11:12.987654-05:00');
    $newCompletion = RepresentativeAcknowledgementCompletion::complete(
        new AcknowledgementRepresentativeId($persistenceRepresentativeIds['primary']),
        new AcknowledgementAcademicPeriodId($persistencePeriodId),
        $completedAt,
        [$requirementB, $updatedRequirementA],
    );
    $persistedCompletion = $completionRepository->save($newCompletion);
    $persistedChildren = $persistedCompletion->acknowledgements();
    assertIntegration(
        ($persistedCompletion->id()?->value() ?? 0) > 0
        && count($persistedChildren) === 2
        && ($persistedChildren[0]->id()?->value() ?? 0) > 0
        && ($persistedChildren[1]->id()?->value() ?? 0) > 0
        && !$persistedChildren[0]->id()?->equals($persistedChildren[1]->id()),
        'MariaDB E009 Completion repository did not generate root and child identities.'
    );
    assertIntegration(
        $persistedCompletion->completedAt()->format('Y-m-d H:i:sP') === '2030-02-03 15:11:12+00:00',
        'MariaDB E009 Completion repository did not preserve exact UTC seconds.'
    );
    assertIntegration(
        $requirementRepository->hasAcknowledgements($updatedRequirementA->id())
        && $requirementRepository->hasAcknowledgements($requirementB->id()),
        'MariaDB E009 Requirement history lookup did not detect persisted children.'
    );

    $updatedRequirementA->deactivate();
    $updatedRequirementA->update(
        $updatedRequirementA->title(),
        new AcknowledgementRequirementUrl('https://example.test/e009/policy-a-current'),
        $updatedRequirementA->officialReference(),
        true,
    );
    $requirementRepository->save($updatedRequirementA);
    $historicalCompletion = $completionRepository->findByRepresentativeAndAcademicPeriod(
        new AcknowledgementRepresentativeId($persistenceRepresentativeIds['primary']),
        new AcknowledgementAcademicPeriodId($persistencePeriodId),
    );
    assertIntegration(
        $historicalCompletion !== null
        && $historicalCompletion->id()?->equals($persistedCompletion->id()) === true
        && count($historicalCompletion->acknowledgements()) === 2,
        'MariaDB E009 historical Completion depended on current Requirement status.'
    );

    $persistedSaveRejected = false;
    try {
        $completionRepository->save($persistedCompletion);
    } catch (RuntimeException) {
        $persistedSaveRejected = true;
    }
    assertIntegration($persistedSaveRejected, 'MariaDB E009 Persistence accepted a mutable save of existing Completion.');

    $duplicateCompletionRejected = false;
    try {
        $completionRepository->save(RepresentativeAcknowledgementCompletion::complete(
            new AcknowledgementRepresentativeId($persistenceRepresentativeIds['primary']),
            new AcknowledgementAcademicPeriodId($persistencePeriodId),
            new DateTimeImmutable('2030-02-04 15:11:12+00:00'),
            [$requirementB],
        ));
    } catch (PDOException) {
        $duplicateCompletionRejected = true;
    }
    assertIntegration(
        $duplicateCompletionRejected && !$connectionA->inTransaction(),
        'MariaDB E009 Completion UNIQUE failure was not rejected and rolled back by the Repository.'
    );

    $otherPeriodRequirement = $requirementRepository->save(
        \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
            new AcknowledgementAcademicPeriodId($persistenceOtherPeriodId),
            new AcknowledgementRequirementTitle('Other-period requirement'),
            new AcknowledgementRequirementUrl('other-period/url'),
            null,
            AcknowledgementRequirementStatus::Active,
        )
    );
    assertMariaDbStatementRejected(
        $connectionA,
        'INSERT INTO representative_acknowledgements '
        . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
        . 'VALUES (:completionId, :requirementId, :academicPeriodId)',
        [
            ':completionId' => $persistedCompletion->id()?->value(),
            ':requirementId' => $otherPeriodRequirement->id()?->value(),
            ':academicPeriodId' => $persistencePeriodId,
        ],
        'MariaDB E009 Persistence allowed cross-period child ownership.'
    );

    $rollbackRequirements = [];
    foreach (['Rollback A', 'Rollback B'] as $rollbackTitle) {
        $rollbackRequirements[] = $requirementRepository->save(
            \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
                new AcknowledgementAcademicPeriodId($persistenceRollbackPeriodId),
                new AcknowledgementRequirementTitle($rollbackTitle),
                new AcknowledgementRequirementUrl('rollback/' . strtolower(str_replace(' ', '-', $rollbackTitle))),
                null,
                AcknowledgementRequirementStatus::Active,
            )
        );
    }
    $rollbackCompletion = RepresentativeAcknowledgementCompletion::complete(
        new AcknowledgementRepresentativeId($persistenceRepresentativeIds['rollback']),
        new AcknowledgementAcademicPeriodId($persistenceRollbackPeriodId),
        new DateTimeImmutable('2032-02-03 15:11:12+00:00'),
        $rollbackRequirements,
    );
    $deleteRollbackRequirement = $connectionA->prepare(
        'DELETE FROM acknowledgement_requirements WHERE id = :id'
    );
    $deleteRollbackRequirement->execute([':id' => $rollbackRequirements[1]->id()?->value()]);
    $childFailureRejected = false;
    try {
        $completionRepository->save($rollbackCompletion);
    } catch (PDOException) {
        $childFailureRejected = true;
    }
    $rolledBackRoot = $connectionA->prepare(
        'SELECT COUNT(*) FROM representative_acknowledgement_completions '
        . 'WHERE representative_id = :representativeId AND academic_period_id = :academicPeriodId'
    );
    $rolledBackRoot->execute([
        ':representativeId' => $persistenceRepresentativeIds['rollback'],
        ':academicPeriodId' => $persistenceRollbackPeriodId,
    ]);
    assertIntegration(
        $childFailureRejected
        && !$connectionA->inTransaction()
        && (int) $rolledBackRoot->fetchColumn() === 0,
        'MariaDB E009 owned Completion transaction did not roll back root and children.'
    );

    $connectionA->beginTransaction();
    try {
        $outerRequirement = $requirementRepository->save(
            \App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
                new AcknowledgementAcademicPeriodId($persistenceOtherPeriodId),
                new AcknowledgementRequirementTitle('Outer transaction requirement'),
                new AcknowledgementRequirementUrl('outer/transaction'),
                null,
                AcknowledgementRequirementStatus::Active,
            )
        );
        assertIntegration(
            $connectionA->inTransaction(),
            'MariaDB E009 Requirement Repository closed the caller transaction.'
        );
        $outerCompletion = $completionRepository->save(RepresentativeAcknowledgementCompletion::complete(
            new AcknowledgementRepresentativeId($persistenceRepresentativeIds['rollback']),
            new AcknowledgementAcademicPeriodId($persistenceOtherPeriodId),
            new DateTimeImmutable('2031-02-03 15:11:12+00:00'),
            [$outerRequirement, $otherPeriodRequirement],
        ));
        assertIntegration(
            $connectionA->inTransaction()
            && ($outerCompletion->id()?->value() ?? 0) > 0,
            'MariaDB E009 Completion Repository did not participate in the caller transaction.'
        );
    } finally {
        $connectionA->rollBack();
    }
    assertIntegration(
        $completionRepository->findByRepresentativeAndAcademicPeriod(
            new AcknowledgementRepresentativeId($persistenceRepresentativeIds['rollback']),
            new AcknowledgementAcademicPeriodId($persistenceOtherPeriodId),
        ) === null,
        'MariaDB E009 caller rollback left an externally coordinated Completion.'
    );

    $insertPersistencePeriod->execute([
        ':code' => 'E009_ADMIN_INACTIVE',
        ':name' => 'E009 administrator inactive period',
        ':startsOn' => '2033-08-01',
        ':endsOn' => '2034-07-31',
        ':statusId' => $inactiveGeneralStatusId,
    ]);
    $administratorInactivePeriodId = (int) $connectionA->lastInsertId();
    $periodProvider = new PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider($managerA);
    $periodOptions = $periodProvider->all();
    assertIntegration(
        $administratorInactivePeriodId > 0
        && $periodOptions[0]->id === $administratorInactivePeriodId
        && $periodOptions[0]->code === 'E009_ADMIN_INACTIVE'
        && $periodProvider->findById($persistencePeriodId)?->name === 'E009 persistence period'
        && $periodProvider->findById(2147483647) === null,
        'MariaDB E009 administrator AcademicPeriod provider filtered status, misordered, or failed revalidation.'
    );

    $getAdministratorRequirements = new GetAcknowledgementRequirements($requirementRepository);
    $createAdministratorRequirement = new CreateAcknowledgementRequirement(
        $requirementRepository,
        new PdoTransactionRunner($managerA),
    );
    $updateAdministratorRequirement = new UpdateAcknowledgementRequirement(
        $requirementRepository,
        new PdoTransactionRunner($managerA),
    );
    $activateAdministratorRequirement = new ActivateAcknowledgementRequirement($requirementRepository);
    $deactivateAdministratorRequirement = new DeactivateAcknowledgementRequirement($requirementRepository);
    assertIntegration(
        count($getAdministratorRequirements->handle($persistencePeriodId)) === 3,
        'MariaDB E009 administrator Application did not list the complete Requirement period state.'
    );

    $administratorRequirement = $createAdministratorRequirement->handle(
        new CreateAcknowledgementRequirementInput(
            $persistenceOtherPeriodId,
            'E009 administrator Requirement ñ',
            'custom:administrator/resource',
            null,
            'INACTIVE',
        )
    );
    $administratorRequirementId = $administratorRequirement->id;
    $updatedAdministratorRequirement = $updateAdministratorRequirement->handle(
        new UpdateAcknowledgementRequirementInput(
            $administratorRequirementId,
            $persistenceOtherPeriodId,
            'E009 administrator Requirement updated ñ',
            'relative/administrator/updated',
            'E009-ADMIN-REF',
        )
    );
    $activatedAdministratorRequirement = $activateAdministratorRequirement->handle(
        $administratorRequirementId,
        $persistenceOtherPeriodId,
    );
    $deactivatedAdministratorRequirement = $deactivateAdministratorRequirement->handle(
        $administratorRequirementId,
        $persistenceOtherPeriodId,
    );
    assertIntegration(
        $administratorRequirementId > 0
        && $updatedAdministratorRequirement->title === 'E009 administrator Requirement updated ñ'
        && $updatedAdministratorRequirement->officialReference === 'E009-ADMIN-REF'
        && $activatedAdministratorRequirement->status === 'ACTIVE'
        && $deactivatedAdministratorRequirement->status === 'INACTIVE',
        'MariaDB E009 administrator Application did not complete same-period create update and status persistence.'
    );

    $crossPeriodRequirementId = $requirementB->id()?->value() ?? 0;
    $crossPeriodBefore = $requirementRepository->findById($requirementB->id());
    foreach (['update', 'activate', 'deactivate'] as $crossPeriodOperation) {
        $crossPeriodRejected = false;
        try {
            if ($crossPeriodOperation === 'update') {
                $updateAdministratorRequirement->handle(new UpdateAcknowledgementRequirementInput(
                    $crossPeriodRequirementId,
                    $persistenceOtherPeriodId,
                    'Cross-period mutation',
                    'cross-period/mutation',
                    null,
                ));
            } elseif ($crossPeriodOperation === 'activate') {
                $activateAdministratorRequirement->handle(
                    $crossPeriodRequirementId,
                    $persistenceOtherPeriodId,
                );
            } else {
                $deactivateAdministratorRequirement->handle(
                    $crossPeriodRequirementId,
                    $persistenceOtherPeriodId,
                );
            }
        } catch (AcknowledgementRequirementNotFound) {
            $crossPeriodRejected = true;
        }
        assertIntegration(
            $crossPeriodRejected,
            'MariaDB E009 administrator Application allowed cross-period ' . $crossPeriodOperation . '.'
        );
    }
    $crossPeriodAfter = $requirementRepository->findById($requirementB->id());
    assertIntegration(
        $crossPeriodBefore !== null
        && $crossPeriodAfter !== null
        && $crossPeriodAfter->title()->equals($crossPeriodBefore->title())
        && $crossPeriodAfter->url()->equals($crossPeriodBefore->url())
        && $crossPeriodAfter->status() === $crossPeriodBefore->status(),
        'MariaDB E009 administrator cross-period rejection changed persisted Requirement state.'
    );

    $administratorPhysical = $connectionA->prepare(
        'SELECT ar.id, st.code AS status_type_code, s.code AS status_code '
        . 'FROM acknowledgement_requirements ar '
        . 'JOIN statuses s ON s.id = ar.status_id '
        . 'JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE ar.id = :id'
    );
    $administratorPhysical->execute([':id' => $administratorRequirementId]);
    $administratorPhysicalRow = $administratorPhysical->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        is_array($administratorPhysicalRow)
        && (int) $administratorPhysicalRow['id'] === $administratorRequirementId
        && $administratorPhysicalRow['status_type_code'] === 'GENERAL_STATUS'
        && $administratorPhysicalRow['status_code'] === 'INACTIVE',
        'MariaDB E009 administrator flow did not preserve AUTO_INCREMENT and exact GENERAL_STATUS mapping.'
    );

    $personFormOptions = (new PdoPersonFormOptionsProvider($managerA))->get();
    assertIntegration(
        count($personFormOptions->documentTypes) === 1
        && count($personFormOptions->sexes) === 1
        && count($personFormOptions->maritalStatuses) === 1
        && count($personFormOptions->educationLevels) === 1,
        'Person form options did not load only active reference Catalog rows.'
    );
    assertIntegration(
        $personFormOptions->isReadyForSave()
        && array_map(
            static fn ($option): string => $option->code,
            $personFormOptions->statuses,
        ) === ['ACTIVE', 'INACTIVE'],
        'Person form options did not load the active GENERAL_STATUS values in order.'
    );
    $personInsert = $identity->prepare(
        'INSERT INTO persons (id, first_name, first_surname, birth_date, sex_id, status_id) '
        . 'VALUES (1, :firstName, :firstSurname, :birthDate, 1, :statusId)'
    );
    $personInsert->execute([
        ':firstName' => 'Disposable',
        ':firstSurname' => 'Administrator',
        ':birthDate' => '2000-01-01',
        ':statusId' => $generalStatusId,
    ]);
    $hash = password_hash('DisposableAdminPassword', PASSWORD_DEFAULT);
    assertIntegration(is_string($hash), 'Unable to create disposable password hash.');
    $insert = $identity->prepare(
        'INSERT INTO users '
        . '(person_id, login_identifier, normalized_login_identifier, password_hash, status_id, failed_login_attempts) '
        . 'VALUES (1, :loginIdentifier, :normalizedLoginIdentifier, :passwordHash, :statusId, 4)'
    );
    $insert->execute([
        ':loginIdentifier' => 'admin',
        ':normalizedLoginIdentifier' => 'admin',
        ':passwordHash' => $hash,
        ':statusId' => $disabledUserStatusId,
    ]);

    (new AdminSeeder())->run($identity);
    $preserved = $identity->query(
        "SELECT password_hash, status_id FROM users WHERE normalized_login_identifier = 'admin'"
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $preserved !== false && $preserved['password_hash'] === $hash,
        'AdminSeeder replaced an existing password hash.'
    );
    assertIntegration(
        $preserved !== false && (int) $preserved['status_id'] === $disabledUserStatusId,
        'AdminSeeder changed an existing User status.'
    );

    $personRepository = new PdoPersonRepository($managerA);
    $personToday = new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC'));
    $person = new Person(
        null,
        new PersonalName('Disposable', 'Maria', 'Persistence', 'Probe'),
        new Identification(1, 'Person-100'),
        new DateTimeImmutable('2000-02-03', new DateTimeZone('UTC')),
        1,
        1,
        1,
        new ContactInformation('person@example.test', 'mobile extension', 'landline extension'),
        PersonStatus::Active,
        $personToday,
    );
    $persistedPerson = $personRepository->save($person);
    $generatedPersonId = $persistedPerson->id();
    assertIntegration($person->id() === null, 'Person insert replaced the identity of the new aggregate instance.');
    assertIntegration(
        $generatedPersonId !== null && $generatedPersonId->value() > 0,
        'MariaDB did not generate a positive Person identity.'
    );
    $secondPerson = new Person(
        null,
        new PersonalName('Second', null, 'Persistence', null),
        null,
        new DateTimeImmutable('2001-01-01', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $personToday,
    );
    $secondPersistedPerson = $personRepository->save($secondPerson);
    $secondGeneratedPersonId = $secondPersistedPerson->id();
    assertIntegration(
        $secondPerson->id() === null,
        'Second Person insert received or replaced a manual identity.'
    );
    assertIntegration(
        $secondGeneratedPersonId !== null && $secondGeneratedPersonId->value() > 0,
        'MariaDB did not generate a positive identity for the second Person.'
    );
    assertIntegration(
        !$generatedPersonId->equals($secondGeneratedPersonId),
        'MariaDB generated the same identity for two Persons.'
    );
    assertIntegration(
        $personRepository->findById($secondGeneratedPersonId)?->personalName()->firstName() === 'Second',
        'Second Person could not be reconstructed through its generated identity.'
    );
    $createdAtStatement = $identity->prepare('SELECT created_at FROM persons WHERE id = :id');
    $createdAtStatement->execute([':id' => $generatedPersonId->value()]);
    $createdAt = $createdAtStatement->fetchColumn();
    assertIntegration(
        $personRepository->findById($generatedPersonId)?->personalName()->middleName() === 'Maria',
        'Person repository did not reconstruct the inserted aggregate by ID.'
    );
    assertIntegration(
        findPersonWithCollationDiagnostics(
            $connectionA,
            $personRepository,
            new Identification(1, '  person-100  '),
        )?->id()?->value()
            === $generatedPersonId->value(),
        'Person repository did not use the normalized identification_key lookup.'
    );

    $duplicateIdentificationRejected = false;
    try {
        $personRepository->save(new Person(
            null,
            new PersonalName('Duplicate', null, 'Identification', null),
            new Identification(1, 'PERSON-100'),
            new DateTimeImmutable('2001-01-01', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            PersonStatus::Active,
            $personToday,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateIdentificationRejected = true;
    }
    assertIntegration(
        $duplicateIdentificationRejected,
        'MariaDB did not enforce normalized Person identification uniqueness.'
    );

    $persistedPerson->updateIdentity(
        new PersonalName('Updated', null, 'Persistence', null),
        null,
        new DateTimeImmutable('2001-04-05', new DateTimeZone('UTC')),
        1,
        null,
        null,
        $personToday,
    );
    $persistedPerson->updateContactInformation(null);
    $persistedPerson->deactivate();
    $updatedPersistedPerson = $personRepository->save($persistedPerson);
    $updatedPersonStatement = $identity->prepare(
        'SELECT p.document_type_id, p.document_number, p.identification_key, '
        . 'p.email, p.mobile_phone, p.landline_phone, p.created_at, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM persons p '
        . 'INNER JOIN statuses s ON s.id = p.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE p.id = :id'
    );
    $updatedPersonStatement->execute([':id' => $generatedPersonId->value()]);
    $updatedPerson = $updatedPersonStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $updatedPerson !== false
        && $updatedPerson['document_type_id'] === null
        && $updatedPerson['document_number'] === null
        && $updatedPerson['identification_key'] === null
        && $updatedPerson['email'] === null
        && $updatedPerson['mobile_phone'] === null
        && $updatedPerson['landline_phone'] === null,
        'Person update did not persist removed identification and contact fields as null.'
    );
    assertIntegration(
        $updatedPerson !== false
        && $updatedPerson['status_type_code'] === 'GENERAL_STATUS'
        && $updatedPerson['status_code'] === 'INACTIVE'
        && (int) $inactiveGeneralStatusId > 0,
        'Person update did not resolve INACTIVE through GENERAL_STATUS.'
    );
    assertIntegration(
        $updatedPerson !== false && $updatedPerson['created_at'] === $createdAt,
        'Person update modified created_at.'
    );
    assertIntegration(
        $updatedPersistedPerson->id()?->value() === $generatedPersonId->value()
        && $updatedPersistedPerson->status() === PersonStatus::Inactive,
        'Person repository did not reconstruct the updated INACTIVE aggregate.'
    );

    $representativeRepository = new PdoRepresentativeRepository($managerA);
    $newRepresentative = new Representative(
        null,
        new RepresentativePersonId($generatedPersonId->value()),
        new EmploymentInformation(
            'Disposable occupation',
            'Disposable company',
            'Disposable position',
            'disposable work phone',
            'representative@example.test',
        ),
        RepresentativeStatus::Active,
    );
    $persistedRepresentative = $representativeRepository->save($newRepresentative);
    $generatedRepresentativeId = $persistedRepresentative->id();
    assertIntegration(
        $newRepresentative->id() === null,
        'Representative insert replaced the identity of the new aggregate instance.'
    );
    assertIntegration(
        $generatedRepresentativeId !== null && $generatedRepresentativeId->value() > 0,
        'MariaDB did not generate a positive Representative identity.'
    );
    assertIntegration(
        $representativeRepository->findById($generatedRepresentativeId)?->employmentInformation()?->companyName()
            === 'Disposable company',
        'Representative repository did not reconstruct complete EmploymentInformation by ID.'
    );
    assertIntegration(
        $representativeRepository->findByPersonId(
            new RepresentativePersonId($generatedPersonId->value())
        )?->id()?->value() === $generatedRepresentativeId->value(),
        'Representative repository did not find the aggregate by PersonId.'
    );

    $duplicateRepresentativeRejected = false;
    try {
        $representativeRepository->save(new Representative(
            null,
            new RepresentativePersonId($generatedPersonId->value()),
            null,
            RepresentativeStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateRepresentativeRejected = true;
    }
    assertIntegration(
        $duplicateRepresentativeRejected,
        'MariaDB did not enforce Representative uniqueness by Person.'
    );

    $persistedRepresentative->replaceEmploymentInformation(
        new EmploymentInformation('Updated occupation', null, null, 'updated phone', null)
    );
    $persistedRepresentative->deactivate();
    $updatedRepresentative = $representativeRepository->save($persistedRepresentative);
    assertIntegration(
        $updatedRepresentative->status() === RepresentativeStatus::Inactive
        && $updatedRepresentative->employmentInformation()?->occupation() === 'Updated occupation'
        && $updatedRepresentative->employmentInformation()?->companyName() === null,
        'Representative update did not persist partial EmploymentInformation and INACTIVE status.'
    );

    $updatedRepresentative->replaceEmploymentInformation(null);
    $updatedRepresentative->activate();
    $clearedRepresentative = $representativeRepository->save($updatedRepresentative);
    $representativeStatusStatement = $identity->prepare(
        'SELECT r.occupation, r.company, r.position, r.work_phone, r.work_email, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM representatives r '
        . 'INNER JOIN statuses s ON s.id = r.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE r.id = :id'
    );
    $representativeStatusStatement->execute([':id' => $generatedRepresentativeId->value()]);
    $representativeRow = $representativeStatusStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeRow !== false
        && $representativeRow['occupation'] === null
        && $representativeRow['company'] === null
        && $representativeRow['position'] === null
        && $representativeRow['work_phone'] === null
        && $representativeRow['work_email'] === null
        && $representativeRow['status_type_code'] === 'GENERAL_STATUS'
        && $representativeRow['status_code'] === 'ACTIVE'
        && $clearedRepresentative->employmentInformation() === null,
        'Representative EmploymentInformation removal or exact GENERAL_STATUS mapping failed.'
    );

    $studentRepository = new PdoStudentRepository($managerA);
    $newStudent = new Student(
        null,
        new StudentPersonId($generatedPersonId->value()),
        new InstitutionalCode('Disposable-Student-100'),
        new AdmissionDate(
            new DateTimeImmutable('2020-09-01', new DateTimeZone('UTC')),
            $personToday,
        ),
        StudentStatus::Active,
    );
    $persistedStudent = $studentRepository->save($newStudent);
    $generatedStudentId = $persistedStudent->id();
    assertIntegration(
        $newStudent->id() === null,
        'Student insert replaced the identity of the new aggregate instance.'
    );
    assertIntegration(
        $generatedStudentId !== null && $generatedStudentId->value() > 0,
        'MariaDB did not generate a positive Student identity.'
    );
    assertIntegration(
        $studentRepository->findById($generatedStudentId)?->admissionDate()->value()->format('Y-m-d')
            === '2020-09-01',
        'Student repository did not reconstruct AdmissionDate by ID.'
    );
    assertIntegration(
        $studentRepository->findByPersonId(
            new StudentPersonId($generatedPersonId->value())
        )?->id()?->value() === $generatedStudentId->value(),
        'Student repository did not find the aggregate by PersonId.'
    );
    assertIntegration(
        $studentRepository->findByInstitutionalCode(
            new InstitutionalCode('disposable-student-100')
        )?->id()?->value() === $generatedStudentId->value(),
        'Student institutional-code lookup did not follow the official table collation.'
    );

    $duplicateStudentPersonRejected = false;
    try {
        $studentRepository->save(new Student(
            null,
            new StudentPersonId($generatedPersonId->value()),
            new InstitutionalCode('OTHER-STUDENT-CODE'),
            new AdmissionDate(
                new DateTimeImmutable('2021-01-01', new DateTimeZone('UTC')),
                $personToday,
            ),
            StudentStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateStudentPersonRejected = true;
    }
    assertIntegration(
        $duplicateStudentPersonRejected,
        'MariaDB did not enforce Student uniqueness by Person.'
    );

    $duplicateStudentCodeRejected = false;
    try {
        $studentRepository->save(new Student(
            null,
            new StudentPersonId($secondGeneratedPersonId->value()),
            new InstitutionalCode('DISPOSABLE-STUDENT-100'),
            new AdmissionDate(
                new DateTimeImmutable('2021-01-02', new DateTimeZone('UTC')),
                $personToday,
            ),
            StudentStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateStudentCodeRejected = true;
    }
    assertIntegration(
        $duplicateStudentCodeRejected,
        'MariaDB did not enforce InstitutionalCode uniqueness under the official collation.'
    );

    $persistedStudent->updateAcademicInformation(
        new InstitutionalCode('Updated-Student-100'),
        new AdmissionDate(
            new DateTimeImmutable('2022-03-04', new DateTimeZone('UTC')),
            $personToday,
        ),
    );
    $persistedStudent->deactivate();
    $updatedStudent = $studentRepository->save($persistedStudent);
    $studentStatusStatement = $identity->prepare(
        'SELECT s.person_id, s.institutional_code, s.admission_date, '
        . 'status_row.code AS status_code, st.code AS status_type_code '
        . 'FROM students s '
        . 'INNER JOIN statuses status_row ON status_row.id = s.status_id '
        . 'INNER JOIN status_types st ON st.id = status_row.status_type_id '
        . 'WHERE s.id = :id'
    );
    $studentStatusStatement->execute([':id' => $generatedStudentId->value()]);
    $studentRow = $studentStatusStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $studentRow !== false
        && (int) $studentRow['person_id'] === $generatedPersonId->value()
        && $studentRow['institutional_code'] === 'Updated-Student-100'
        && $studentRow['admission_date'] === '2022-03-04'
        && $studentRow['status_type_code'] === 'GENERAL_STATUS'
        && $studentRow['status_code'] === 'INACTIVE'
        && $updatedStudent->status() === StudentStatus::Inactive,
        'Student administrative update or exact GENERAL_STATUS mapping failed.'
    );

    $relationshipTypeInsert = $identity->prepare(
        'INSERT INTO relationship_types (code, name, is_active) '
        . 'VALUES (:code, :name, TRUE)'
    );
    $relationshipTypeInsert->execute([
        ':code' => 'DISPOSABLE_TEST_RELATIONSHIP',
        ':name' => 'Disposable test relationship',
    ]);
    $generatedRelationshipTypeId = (int) $identity->lastInsertId();
    assertIntegration(
        $generatedRelationshipTypeId > 0,
        'MariaDB did not generate the technical RelationshipType identity.'
    );
    $relationshipTypeInsert = $identity->prepare(
        'INSERT INTO relationship_types (code, name, is_active) '
        . 'VALUES (:code, :name, FALSE)'
    );
    $relationshipTypeInsert->execute([
        ':code' => 'DISPOSABLE_INACTIVE_RELATIONSHIP',
        ':name' => 'Inactive disposable relationship',
    ]);
    $inactiveRelationshipTypeId = (int) $identity->lastInsertId();
    assertIntegration(
        $inactiveRelationshipTypeId > 0,
        'MariaDB did not generate the inactive RelationshipType identity.'
    );

    $secondRepresentative = $representativeRepository->save(new Representative(
        null,
        new RepresentativePersonId($secondGeneratedPersonId->value()),
        null,
        RepresentativeStatus::Active,
    ));
    $secondRepresentativeId = $secondRepresentative->id();
    assertIntegration(
        $secondRepresentativeId !== null && $secondRepresentativeId->value() > 0,
        'MariaDB did not generate the additional Representative identity.'
    );

    $secondStudent = $studentRepository->save(new Student(
        null,
        new StudentPersonId($secondGeneratedPersonId->value()),
        new InstitutionalCode('DISPOSABLE-STUDENT-200'),
        new AdmissionDate(
            new DateTimeImmutable('2021-01-02', new DateTimeZone('UTC')),
            $personToday,
        ),
        StudentStatus::Active,
    ));
    $secondStudentId = $secondStudent->id();
    assertIntegration(
        $secondStudentId !== null && $secondStudentId->value() > 0,
        'MariaDB did not generate the additional Student identity.'
    );

    $familyRepository = new PdoFamilyRepository($managerA);
    $familyStartedAt = new DateTimeImmutable('2026-08-01 10:11:12-05:00');
    $firstFamilyCode = new FamilyCode('F81500001');
    $newFamily = Family::create(
        $firstFamilyCode,
        new DisplayName('Disposable Family One'),
        FamilyStatus::Active,
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        $familyStartedAt,
    );
    $newFamily->addRepresentative(
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-02 09:00:00', new DateTimeZone('UTC')),
    );
    $newFamily->addStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-03 09:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily = $familyRepository->save($newFamily);
    $generatedFamilyId = $persistedFamily->id();
    $initialFamilyRepresentative = $persistedFamily->primaryRepresentative();
    assertIntegration(
        $newFamily->id() === null
        && $generatedFamilyId !== null
        && $generatedFamilyId->value() > 0
        && $initialFamilyRepresentative->id() !== null
        && $initialFamilyRepresentative->id()->value() > 0
        && $initialFamilyRepresentative->isActive()
        && $initialFamilyRepresentative->isPrimary()
        && $persistedFamily->familyCode()->equals($firstFamilyCode),
        'MariaDB did not atomically generate Family and its active primary membership identities.'
    );
    assertIntegration(
        count($persistedFamily->representatives()) === 2
        && count($persistedFamily->students()) === 1
        && $persistedFamily->students()[0]->id() !== null
        && $persistedFamily->students()[0]->id()->value() > 0,
        'Family repository did not reconstruct every generated membership identity.'
    );
    assertIntegration(
        $familyRepository->findByCode($firstFamilyCode)?->id()?->equals($generatedFamilyId) === true,
        'MariaDB exact FamilyCode lookup did not reconstruct the complete Aggregate.'
    );
    $lowercaseCodeLookup = $identity->prepare(
        'SELECT COUNT(*) FROM families WHERE family_code = :familyCode'
    );
    $lowercaseCodeLookup->execute([':familyCode' => strtolower($firstFamilyCode->value())]);
    assertIntegration(
        (int) $lowercaseCodeLookup->fetchColumn() === 0,
        'MariaDB FamilyCode lookup did not preserve exact ASCII-binary case behavior.'
    );
    $connectionA->beginTransaction();
    try {
        $lockedByCode = $familyRepository->findByCodeForUpdate($firstFamilyCode);
        assertIntegration(
            $lockedByCode?->id()?->equals($generatedFamilyId) === true,
            'MariaDB FamilyCode FOR UPDATE lookup did not lock and reconstruct the expected Aggregate.'
        );
    } finally {
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }

    $duplicateFamilyCodeRejected = false;
    try {
        $familyRepository->save(Family::create(
            $firstFamilyCode,
            new DisplayName('Duplicate FamilyCode'),
            FamilyStatus::Active,
            new FamilyRepresentativeReference($secondRepresentativeId->value()),
            new RelationshipTypeId($generatedRelationshipTypeId),
            new DateTimeImmutable('2026-08-01 16:00:00', new DateTimeZone('UTC')),
        ));
    } catch (FamilyCodeAlreadyExists) {
        $duplicateFamilyCodeRejected = true;
    }
    assertIntegration(
        $duplicateFamilyCodeRejected,
        'MariaDB FamilyCode UNIQUE collision was not mapped to the exact Domain exception.'
    );

    $familyTimestampStatement = $identity->prepare(
        'SELECT fr.started_at, fr.ended_at, fr.is_primary, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM family_representatives fr '
        . 'INNER JOIN families f ON f.id = fr.family_id '
        . 'INNER JOIN statuses s ON s.id = f.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . 'WHERE fr.id = :id'
    );
    $familyTimestampStatement->execute([':id' => $initialFamilyRepresentative->id()->value()]);
    $familyTimestampRow = $familyTimestampStatement->fetch(PDO::FETCH_ASSOC);
    $familyTimeZoneRow = $identity->query(
        'SELECT @@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone'
    )->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $familyTimestampRow !== false
        && $familyTimestampRow['started_at'] === '2026-08-01 15:11:12'
        && $familyTimestampRow['ended_at'] === null
        && (int) $familyTimestampRow['is_primary'] === 1
        && $familyTimestampRow['status_type_code'] === 'GENERAL_STATUS'
        && $familyTimestampRow['status_code'] === 'ACTIVE',
        mariaDbFamilyPersistenceDiagnostics($familyTimestampRow, $familyTimeZoneRow)
    );

    $persistedFamily->addAddress(
        new AddressLabel('Casa O\'Brien'),
        new Address(
            'Av. José O\'Brien',
            'N-42',
            'Calle Secundaria',
            'Sector Ñ',
            'Referencia exacta',
            new Geolocation('-0.1234567', '179.9999999'),
        ),
    );
    $persistedFamily->addEmergencyContact(
        new FamilyResourceName('María D\'Angelo'),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new EmergencyContactInformation('móvil +593', 'teléfono', 'maria@example.test', 'Observación'),
    );
    $persistedFamily->addAuthorizedPickup(
        new FamilyResourceName('José O\'Neil'),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new AuthorizedPickupInformation('pickup +593', 'fijo', 'Autorizado'),
        new PickupIdentification(new FamilyDocumentTypeId(1), 'PICKUP-Ñ-001'),
    );
    $persistedFamily = $familyRepository->save($persistedFamily);
    $familyAddressId = $persistedFamily->addresses()[0]->id();
    $familyEmergencyContactId = $persistedFamily->emergencyContacts()[0]->id();
    $familyAuthorizedPickupId = $persistedFamily->authorizedPickups()[0]->id();
    assertIntegration(
        $familyAddressId !== null && $familyAddressId->value() > 0
        && $familyEmergencyContactId !== null && $familyEmergencyContactId->value() > 0
        && $familyAuthorizedPickupId !== null && $familyAuthorizedPickupId->value() > 0,
        'MariaDB did not generate positive Family resource identities.'
    );

    $persistedFamily->assignAddressToRepresentative(
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        $familyAddressId,
        new DateTimeImmutable('2026-08-03 10:01:02-05:00'),
    );
    $persistedFamily->assignAddressToStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        $familyAddressId,
        new DateTimeImmutable('2026-08-03 15:02:03', new DateTimeZone('UTC')),
    );
    $persistedFamily->assignEmergencyContactToStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        $familyEmergencyContactId,
        new EmergencyContactPriority(1),
        new DateTimeImmutable('2026-08-03 15:03:04', new DateTimeZone('UTC')),
    );
    $persistedFamily->assignAuthorizedPickupToStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        $familyAuthorizedPickupId,
        new DateTimeImmutable('2026-08-03 15:04:05', new DateTimeZone('UTC')),
    );
    $persistedFamily = $familyRepository->save($persistedFamily);
    assertIntegration(
        count($persistedFamily->addresses()) === 1
        && count($persistedFamily->representativeAddressAssignments()) === 1
        && count($persistedFamily->studentAddressAssignments()) === 1
        && count($persistedFamily->emergencyContacts()) === 1
        && count($persistedFamily->emergencyContactAssignments()) === 1
        && count($persistedFamily->authorizedPickups()) === 1
        && count($persistedFamily->authorizedPickupAssignments()) === 1
        && $persistedFamily->addresses()[0]->address()->geolocation()?->latitude() === '-0.1234567'
        && $persistedFamily->addresses()[0]->address()->geolocation()?->longitude() === '179.9999999',
        'MariaDB did not reconstruct the complete Family Resources aggregate.'
    );
    $resourceAssignmentIds = [
        $persistedFamily->representativeAddressAssignments()[0]->id()?->value(),
        $persistedFamily->studentAddressAssignments()[0]->id()?->value(),
        $persistedFamily->emergencyContactAssignments()[0]->id()?->value(),
        $persistedFamily->authorizedPickupAssignments()[0]->id()?->value(),
    ];
    assertIntegration(
        count(array_filter($resourceAssignmentIds, static fn (?int $id): bool => $id !== null && $id > 0)) === 4,
        'MariaDB did not generate every Family resource assignment identity.'
    );
    $resourceUtc = $identity->prepare(
        'SELECT started_at FROM representative_address_assignments WHERE id = :id'
    );
    $resourceUtc->execute([':id' => $resourceAssignmentIds[0]]);
    assertIntegration(
        $resourceUtc->fetchColumn() === '2026-08-03 15:01:02',
        'Family resource assignment did not preserve exact UTC seconds.'
    );

    $duplicateEmergencyAssignmentRejected = false;
    try {
        $duplicateEmergency = $identity->prepare(
            'INSERT INTO emergency_contact_assignments '
            . '(family_id, family_emergency_contact_id, student_id, priority, started_at, ended_at) '
            . 'VALUES (:familyId, :contactId, :studentId, 2, :startedAt, NULL)'
        );
        $duplicateEmergency->execute([
            ':familyId' => $generatedFamilyId->value(),
            ':contactId' => $familyEmergencyContactId->value(),
            ':studentId' => $generatedStudentId->value(),
            ':startedAt' => '2026-08-03 16:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateEmergencyAssignmentRejected = true;
    }
    assertIntegration(
        $duplicateEmergencyAssignmentRejected,
        'MariaDB did not enforce generated active Family resource assignment uniqueness.'
    );

    $familyCountBeforeResourceRollback = (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn();
    $resourceRollback = Family::create(
        \Tests\FamilyCodeTestFactory::next(),
        new DisplayName('Family Resource Rollback'),
        FamilyStatus::Active,
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-03 17:00:00', new DateTimeZone('UTC')),
    );
    $resourceRollback->addEmergencyContact(
        new FamilyResourceName('Invalid relationship'),
        new RelationshipTypeId(999999999),
        new EmergencyContactInformation('mobile', null, null, null),
    );
    $resourceRollbackRejected = false;
    try {
        $familyRepository->save($resourceRollback);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $resourceRollbackRejected = true;
    }
    assertIntegration(
        $resourceRollbackRejected
        && (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn() === $familyCountBeforeResourceRollback
        && $familyRepository->findByCode($resourceRollback->familyCode()) === null,
        'Family resource failure did not roll back the owned transaction atomically.'
    );

    $persistedFamily->endRepresentativeAddressAssignment(
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        new DateTimeImmutable('2026-08-03 18:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily->endStudentAddressAssignment(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-03 18:00:01', new DateTimeZone('UTC')),
    );
    $persistedFamily->endEmergencyContactAssignment(
        new FamilyStudentReference($generatedStudentId->value()),
        $familyEmergencyContactId,
        new DateTimeImmutable('2026-08-03 18:00:02', new DateTimeZone('UTC')),
    );
    $persistedFamily->endAuthorizedPickupAssignment(
        new FamilyStudentReference($generatedStudentId->value()),
        $familyAuthorizedPickupId,
        new DateTimeImmutable('2026-08-03 18:00:03', new DateTimeZone('UTC')),
    );
    $persistedFamily->deactivateAddress($familyAddressId);
    $persistedFamily->deactivateEmergencyContact($familyEmergencyContactId);
    $persistedFamily->deactivateAuthorizedPickup($familyAuthorizedPickupId);
    $persistedFamily = $familyRepository->save($persistedFamily);
    assertIntegration(
        $persistedFamily->addresses()[0]->status() === FamilyResourceStatus::Inactive
        && $persistedFamily->emergencyContacts()[0]->status() === FamilyResourceStatus::Inactive
        && $persistedFamily->authorizedPickups()[0]->status() === FamilyResourceStatus::Inactive
        && !$persistedFamily->studentAddressAssignments()[0]->isActive(),
        'Family resource status or historical assignment update was not persisted exactly.'
    );

    $secondFamily = $familyRepository->save(Family::create(
        \Tests\FamilyCodeTestFactory::next(),
        new DisplayName('Disposable Family Two'),
        FamilyStatus::Inactive,
        new FamilyRepresentativeReference($generatedRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-04 09:00:00', new DateTimeZone('UTC')),
    ));
    $generatedSecondFamilyId = $secondFamily->id();
    assertIntegration(
        $generatedSecondFamilyId !== null
        && $generatedSecondFamilyId->value() > 0
        && !$generatedFamilyId->equals($generatedSecondFamilyId),
        'MariaDB Family AUTO_INCREMENT identities must be positive and distinct.'
    );
    $secondFamily->addAddress(
        new AddressLabel('Second Family Address'),
        new Address('Second street', null, null, null, null, null),
    );
    $secondFamily = $familyRepository->save($secondFamily);
    $secondFamilyAddressId = $secondFamily->addresses()[0]->id();
    assertIntegration(
        $secondFamilyAddressId !== null
        && $secondFamilyAddressId->value() > 0
        && !$familyAddressId->equals($secondFamilyAddressId),
        'MariaDB FamilyAddress identities were not positive and distinct.'
    );

    $crossFamilyResourceRejected = false;
    try {
        $crossFamilyAssignment = $identity->prepare(
            'INSERT INTO representative_address_assignments '
            . '(family_id, family_address_id, representative_id, started_at, ended_at) '
            . 'VALUES (:familyId, :addressId, :representativeId, :startedAt, NULL)'
        );
        $crossFamilyAssignment->execute([
            ':familyId' => $generatedSecondFamilyId->value(),
            ':addressId' => $familyAddressId->value(),
            ':representativeId' => $generatedRepresentativeId->value(),
            ':startedAt' => '2026-08-04 10:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $crossFamilyResourceRejected = true;
    }
    assertIntegration(
        $crossFamilyResourceRejected,
        'MariaDB composite Family resource ownership foreign key was not enforced.'
    );

    $pickupDocumentPairRejected = false;
    try {
        $invalidPickup = $identity->prepare(
            'INSERT INTO family_authorized_pickups '
            . '(family_id, names, relationship_type_id, mobile_phone, document_type_id, document_number, status_id) '
            . 'VALUES (:familyId, :names, :relationshipTypeId, :mobilePhone, 1, NULL, :statusId)'
        );
        $invalidPickup->execute([
            ':familyId' => $generatedSecondFamilyId->value(), ':names' => 'Invalid pair',
            ':relationshipTypeId' => $generatedRelationshipTypeId, ':mobilePhone' => 'mobile',
            ':statusId' => $generalStatusId,
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $pickupDocumentPairRejected = true;
    }
    assertIntegration($pickupDocumentPairRejected, 'MariaDB did not enforce the pickup document pair check.');

    $emergencyPriorityCheckRejected = false;
    try {
        $invalidPriority = $identity->prepare(
            'INSERT INTO emergency_contact_assignments '
            . '(family_id, family_emergency_contact_id, student_id, priority, started_at, ended_at) '
            . 'VALUES (:familyId, :contactId, :studentId, 0, :startedAt, :endedAt)'
        );
        $invalidPriority->execute([
            ':familyId' => $generatedFamilyId->value(), ':contactId' => $familyEmergencyContactId->value(),
            ':studentId' => $generatedStudentId->value(), ':startedAt' => '2026-08-04 11:00:00',
            ':endedAt' => '2026-08-04 11:00:01',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $emergencyPriorityCheckRejected = true;
    }
    assertIntegration(
        $emergencyPriorityCheckRejected,
        'MariaDB did not enforce positive optional emergency assignment priority.'
    );

    $resourceCountBeforeOuterRollback = (int) $identity->query(
        'SELECT COUNT(*) FROM family_addresses'
    )->fetchColumn();
    $connectionA->beginTransaction();
    $secondFamily->addAddress(
        new AddressLabel('Outer transaction rollback'),
        new Address('Rollback street', null, null, null, null, null),
    );
    $outerResourceFamily = $familyRepository->save($secondFamily);
    assertIntegration(
        $connectionA->inTransaction() && count($outerResourceFamily->addresses()) === 2,
        'Family resource persistence did not participate in the outer transaction.'
    );
    $connectionA->rollBack();
    assertIntegration(
        (int) $identity->query('SELECT COUNT(*) FROM family_addresses')->fetchColumn()
            === $resourceCountBeforeOuterRollback,
        'Outer transaction rollback did not remove the Family resource write.'
    );
    $secondFamily = $familyRepository->findById($generatedSecondFamilyId);
    assertIntegration($secondFamily !== null, 'Second Family disappeared after outer rollback validation.');
    $representativeFamilies = $familyRepository->findActiveByRepresentativeId(
        new FamilyRepresentativeReference($generatedRepresentativeId->value())
    );
    assertIntegration(
        array_map(
            static fn (Family $family): ?int => $family->id()?->value(),
            $representativeFamilies,
        ) === [$generatedFamilyId->value(), $generatedSecondFamilyId->value()],
        'Representative active-Family lookup did not return all complete Families deterministically.'
    );
    assertIntegration(
        $familyRepository->findActiveByStudentId(
            new FamilyStudentReference($generatedStudentId->value())
        )?->id()?->value() === $generatedFamilyId->value(),
        'Student active-Family lookup did not reconstruct the complete Family.'
    );

    $duplicateActiveRepresentativeRejected = false;
    try {
        $duplicateActiveRepresentative = $identity->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES (:familyId, :representativeId, :relationshipTypeId, FALSE, :startedAt, NULL)'
        );
        $duplicateActiveRepresentative->execute([
            ':familyId' => $generatedFamilyId->value(),
            ':representativeId' => $generatedRepresentativeId->value(),
            ':relationshipTypeId' => $generatedRelationshipTypeId,
            ':startedAt' => '2026-08-05 09:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateActiveRepresentativeRejected = true;
    }
    assertIntegration(
        $duplicateActiveRepresentativeRejected,
        'MariaDB did not enforce unique active Family and Representative membership.'
    );

    $persistedFamily->endRepresentativeMembership(
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new DateTimeImmutable('2026-08-06 09:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily->endStudentMembership(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-06 10:00:00', new DateTimeZone('UTC')),
    );
    $persistedFamily->updateDisplayName(new DisplayName('Disposable Family Updated'));
    $persistedFamily->deactivate();
    $updatedFamily = $familyRepository->save($persistedFamily);
    assertIntegration(
        $updatedFamily->displayName()->value() === 'Disposable Family Updated'
        && $updatedFamily->familyCode()->equals($firstFamilyCode)
        && $updatedFamily->status() === FamilyStatus::Inactive
        && count($updatedFamily->representatives()) === 2
        && count($updatedFamily->activeRepresentatives()) === 1
        && count($updatedFamily->students()) === 1
        && count($updatedFamily->activeStudents()) === 0,
        'Family update did not preserve history or persist the approved mutable state.'
    );

    $principalUniquenessRejected = false;
    try {
        $secondPrimary = $identity->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES (:familyId, :representativeId, :relationshipTypeId, TRUE, :startedAt, NULL)'
        );
        $secondPrimary->execute([
            ':familyId' => $generatedFamilyId->value(),
            ':representativeId' => $secondRepresentativeId->value(),
            ':relationshipTypeId' => $generatedRelationshipTypeId,
            ':startedAt' => '2026-08-06 11:00:00',
        ]);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $principalUniquenessRejected = true;
    }
    assertIntegration(
        $principalUniquenessRejected,
        'MariaDB did not enforce one active primary Representative per Family.'
    );

    $immutableMembershipStatement = $identity->prepare(
        'SELECT representative_id, relationship_type_id, is_primary, started_at, ended_at '
        . 'FROM family_representatives WHERE family_id = :familyId AND representative_id = :representativeId'
    );
    $immutableMembershipStatement->execute([
        ':familyId' => $generatedFamilyId->value(),
        ':representativeId' => $secondRepresentativeId->value(),
    ]);
    $immutableMembershipRow = $immutableMembershipStatement->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $immutableMembershipRow !== false
        && (int) $immutableMembershipRow['representative_id'] === $secondRepresentativeId->value()
        && (int) $immutableMembershipRow['relationship_type_id'] === $generatedRelationshipTypeId
        && (int) $immutableMembershipRow['is_primary'] === 0
        && $immutableMembershipRow['started_at'] === '2026-08-02 09:00:00'
        && $immutableMembershipRow['ended_at'] === '2026-08-06 09:00:00',
        'FamilyRepresentative update changed immutable persisted fields.'
    );

    $secondFamily->addStudent(
        new FamilyStudentReference($generatedStudentId->value()),
        new DateTimeImmutable('2026-08-07 09:00:00', new DateTimeZone('UTC')),
    );
    $secondFamily = $familyRepository->save($secondFamily);
    assertIntegration(
        $familyRepository->findActiveByStudentId(
            new FamilyStudentReference($generatedStudentId->value())
        )?->id()?->value() === $generatedSecondFamilyId->value(),
        'MariaDB did not permit a later Student membership after the previous one ended.'
    );

    $duplicateActiveStudentRejected = false;
    try {
        $persistedAgain = $familyRepository->findById($generatedFamilyId);
        assertIntegration($persistedAgain !== null, 'Family disappeared before Student uniqueness probe.');
        $persistedAgain->addStudent(
            new FamilyStudentReference($generatedStudentId->value()),
            new DateTimeImmutable('2026-08-08 09:00:00', new DateTimeZone('UTC')),
        );
        $familyRepository->save($persistedAgain);
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $duplicateActiveStudentRejected = true;
    }
    assertIntegration(
        $duplicateActiveStudentRejected,
        'MariaDB did not enforce one active Family per Student.'
    );

    $invalidRelationshipRejected = false;
    $familyCountBeforeRollback = (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn();
    try {
        $familyRepository->save(Family::create(
            \Tests\FamilyCodeTestFactory::next(),
            new DisplayName('Rollback Probe Family'),
            FamilyStatus::Active,
            new FamilyRepresentativeReference($secondRepresentativeId->value()),
            new RelationshipTypeId(999999999),
            new DateTimeImmutable('2026-08-09 09:00:00', new DateTimeZone('UTC')),
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $invalidRelationshipRejected = true;
    }
    assertIntegration(
        $invalidRelationshipRejected
        && (int) $identity->query('SELECT COUNT(*) FROM families')->fetchColumn()
            === $familyCountBeforeRollback,
        'Family creation failure did not roll back its root row atomically.'
    );
    assertIntegration(
        (int) $identity->query(
            'SELECT COUNT(*) FROM families f '
            . 'LEFT JOIN family_representatives fr ON fr.family_id = f.id '
            . 'WHERE fr.id IS NULL'
        )->fetchColumn() === 0,
        'Family persistence left an orphan Family without a Representative membership.'
    );

    $relationshipTypes = new PdoRelationshipTypeLookup($managerA);
    assertIntegration(
        $relationshipTypes->exists($generatedRelationshipTypeId)
        && !$relationshipTypes->exists($inactiveRelationshipTypeId)
        && !$relationshipTypes->exists(0)
        && !$relationshipTypes->exists(999999999),
        'Productive RelationshipTypeLookup did not enforce positive active catalog identity.'
    );
    $familyFormOptions = (new PdoFamilyFormOptionsProvider($managerA))->get();
    assertIntegration(
        $familyFormOptions->isReadyForSave()
        && array_map(
            static fn ($option): string => $option->code,
            $familyFormOptions->relationshipTypes,
        ) === ['DISPOSABLE_TEST_RELATIONSHIP']
        && $familyFormOptions->statuses === [FamilyStatus::Active, FamilyStatus::Inactive],
        'Productive Family form options did not expose only active relationships and exact statuses.'
    );
    $documentTypes = new PdoDocumentTypeLookup($managerA);
    assertIntegration(
        $documentTypes->exists(1)
        && !$documentTypes->exists(2)
        && !$documentTypes->exists(0)
        && !$documentTypes->exists(999999999),
        'Productive DocumentTypeLookup did not enforce positive active catalog identity.'
    );
    $familyResourceOptions = (new PdoFamilyResourceFormOptionsProvider($managerA))->get();
    assertIntegration(
        array_map(
            static fn ($option): string => $option->code,
            $familyResourceOptions->relationshipTypes,
        ) === ['DISPOSABLE_TEST_RELATIONSHIP']
        && array_map(
            static fn ($option): string => $option->code,
            $familyResourceOptions->documentTypes,
        ) === ['TEST'],
        'Productive Family Resource form options did not expose only active ordered catalogs.'
    );
    $deliveryAddress = (new CreateFamilyAddress($familyRepository))->handle(
        new CreateFamilyAddressInput(
            $generatedFamilyId->value(),
            'Delivery Address',
            'Delivery street',
            null,
            null,
            null,
            null,
            null,
            null,
        )
    );
    $deliveryResources = (new GetFamilyResources($familyRepository))->handle($generatedFamilyId->value());
    assertIntegration(
        $deliveryAddress->id > 0
        && count(array_filter(
            $deliveryResources->addresses,
            static fn ($address): bool => $address->id === $deliveryAddress->id
                && $address->label === 'Delivery Address'
                && $address->status === 'ACTIVE',
        )) === 1,
        'Family Resource Application did not create and reconstruct the delivery Address physically.'
    );
    $transactions = new PdoTransactionRunner($managerA);
    $createPerson = new CreatePerson($personRepository);
    $createRepresentative = new CreateRepresentative(
        $personRepository,
        $representativeRepository,
    );
    $representativeUsers = new PdoUserRepository($managerA);
    $representativePasswordHasher = new NativePasswordHasher();
    $representativePasswordPolicy = new RepresentativePasswordPolicy();
    $createRepresentativeUser = new CreateRepresentativeUser(
        $representativeRepository,
        $personRepository,
        $representativeUsers,
        $representativePasswordHasher,
        $representativePasswordPolicy,
    );
    $createRepresentativeAccess = new CreateRepresentativeAccess(
        $createPerson,
        new \App\Person\Application\GetPerson($personRepository),
        $createRepresentative,
        $createRepresentativeUser,
    );
    $createStudent = new CreateStudent($personRepository, $studentRepository);
    $createFamily = new CreateFamily(
        $familyRepository,
        $representativeRepository,
        $relationshipTypes,
        new \App\Family\Infrastructure\Generation\RandomFamilyCodeGenerator(),
    );
    $compositeToday = new DateTimeImmutable('2026-08-04', new DateTimeZone('UTC'));
    $representativeFlow = new CreateRepresentativeFamily(
        $transactions,
        $createRepresentativeAccess,
        $createFamily,
    );
    $representativeFlowOutput = $representativeFlow->handle(
        new CreateRepresentativeFamilyInput(
            firstName: 'Composite',
            middleName: 'MariaDB',
            firstSurname: 'Representative',
            secondSurname: 'Success',
            documentTypeId: 1,
            documentNumber: 'COMPOSITE-REP-SUCCESS',
            birthDate: new DateTimeImmutable('1985-04-05', new DateTimeZone('UTC')),
            sexId: 1,
            maritalStatusId: 1,
            educationLevelId: 1,
            email: 'composite-representative@example.test',
            mobilePhone: 'composite mobile',
            landlinePhone: null,
            personStatus: PersonStatus::Active,
            occupation: 'Tester',
            companyName: null,
            position: null,
            workPhone: null,
            workEmail: 'composite-work@example.test',
            representativeStatus: RepresentativeStatus::Active,
            initialPassword: 'composite-initial-secret',
            userStatus: UserStatus::Active,
            displayName: 'MariaDB Composite Representative Family',
            familyStatus: FamilyStatus::Active,
            relationshipTypeId: $generatedRelationshipTypeId,
            startedAt: new DateTimeImmutable('2026-08-10 10:11:12-05:00'),
        ),
        $compositeToday,
    );
    $representativeFlowRow = $identity->prepare(
        'SELECT p.id AS person_id, p.email, r.id AS representative_id, '
        . 'u.id AS user_id, u.person_id AS user_person_id, u.login_identifier, u.password_hash, '
        . 'us.code AS user_status_code, ust.code AS user_status_type_code, f.id AS family_id, '
        . 'fr.id AS membership_id, fr.representative_id AS membership_representative_id, '
        . 'fr.relationship_type_id, fr.is_primary, fr.ended_at '
        . 'FROM persons p INNER JOIN representatives r ON r.person_id = p.id '
        . 'INNER JOIN users u ON u.person_id = p.id '
        . 'INNER JOIN statuses us ON us.id = u.status_id '
        . 'INNER JOIN status_types ust ON ust.id = us.status_type_id '
        . 'INNER JOIN family_representatives fr ON fr.representative_id = r.id '
        . 'INNER JOIN families f ON f.id = fr.family_id '
        . 'WHERE p.document_number = :documentNumber AND f.display_name = :displayName'
    );
    $representativeFlowRow->execute([
        ':documentNumber' => 'COMPOSITE-REP-SUCCESS',
        ':displayName' => 'MariaDB Composite Representative Family',
    ]);
    $representativePhysical = $representativeFlowRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativePhysical !== false
        && (int) $representativePhysical['person_id'] === $representativeFlowOutput->person->id
        && $representativePhysical['email'] === 'composite-representative@example.test'
        && (int) $representativePhysical['representative_id']
            === $representativeFlowOutput->representative->id
        && (int) $representativePhysical['user_id'] === $representativeFlowOutput->user->userId
        && (int) $representativePhysical['user_person_id'] === $representativeFlowOutput->person->id
        && $representativePhysical['login_identifier'] === 'composite-rep-success'
        && $representativePhysical['password_hash'] !== 'composite-initial-secret'
        && $representativePasswordHasher->verify(
            'composite-initial-secret',
            (string) $representativePhysical['password_hash'],
        )
        && $representativePhysical['user_status_type_code'] === 'USER_STATUS'
        && $representativePhysical['user_status_code'] === 'ACTIVE'
        && (int) $representativePhysical['family_id'] === $representativeFlowOutput->family->id
        && (int) $representativePhysical['membership_id'] > 0
        && (int) $representativePhysical['membership_representative_id']
            === $representativeFlowOutput->representative->id
        && (int) $representativePhysical['relationship_type_id'] === $generatedRelationshipTypeId
        && (int) $representativePhysical['is_primary'] === 1
        && $representativePhysical['ended_at'] === null
        && !$connectionA->inTransaction(),
        'Composite Representative flow did not commit Person, role, User, Family and primary membership.'
    );

    $missingEmailPerson = $personRepository->save(new Person(
        null,
        new PersonalName('Missing', null, 'Representative', 'Email'),
        new Identification(1, 'REPRESENTATIVE-MISSING-EMAIL'),
        new DateTimeImmutable('1987-06-07', new DateTimeZone('UTC')),
        1,
        null,
        null,
        new ContactInformation(null, 'phone only', null),
        PersonStatus::Active,
        $personToday,
    ));
    $missingEmailPersonId = $missingEmailPerson->id();
    assertIntegration(
        $missingEmailPersonId !== null,
        'MariaDB did not persist the Person used for Representative email rejection.'
    );
    $missingEmailRepresentativeRejected = false;
    try {
        $createRepresentative->handle(new CreateRepresentativeInput(
            $missingEmailPersonId->value(),
            'Tester',
            null,
            null,
            null,
            'work-email-does-not-substitute@example.test',
            RepresentativeStatus::Active,
        ));
    } catch (RepresentativeRequiresContactEmail) {
        $missingEmailRepresentativeRejected = true;
    }
    assertIntegration(
        $missingEmailRepresentativeRejected
        && $representativeRepository->findByPersonId(
            new RepresentativePersonId($missingEmailPersonId->value())
        ) === null,
        'Representative creation accepted a Person without personal email or persisted the role.'
    );

    $representativeRollbackRejected = false;
    try {
        $representativeFlow->handle(
            new CreateRepresentativeFamilyInput(
                firstName: 'Composite',
                middleName: 'MariaDB',
                firstSurname: 'Representative',
                secondSurname: 'Rollback',
                documentTypeId: 1,
                documentNumber: 'COMPOSITE-REP-ROLLBACK',
                birthDate: new DateTimeImmutable('1986-05-06', new DateTimeZone('UTC')),
                sexId: 1,
                maritalStatusId: null,
                educationLevelId: null,
                email: '',
                mobilePhone: null,
                landlinePhone: null,
                personStatus: PersonStatus::Active,
                occupation: null,
                companyName: null,
                position: null,
                workPhone: null,
                workEmail: 'composite-work-does-not-substitute@example.test',
                representativeStatus: RepresentativeStatus::Active,
                initialPassword: 'composite-rollback-secret',
                userStatus: UserStatus::Active,
                displayName: 'MariaDB Composite Representative Rollback',
                familyStatus: FamilyStatus::Active,
                relationshipTypeId: $generatedRelationshipTypeId,
                startedAt: new DateTimeImmutable('2026-08-10 12:00:00', new DateTimeZone('UTC')),
            ),
            $compositeToday,
        );
    } catch (RepresentativeRequiresContactEmail) {
        $representativeRollbackRejected = true;
    }
    $representativeRollbackCounts = $identity->prepare(
        'SELECT '
        . '(SELECT COUNT(*) FROM persons WHERE document_number = :personDocumentNumber) AS persons_count, '
        . '(SELECT COUNT(*) FROM representatives r INNER JOIN persons p ON p.id = r.person_id '
        . 'WHERE p.document_number = :representativeDocumentNumber) AS representatives_count, '
        . '(SELECT COUNT(*) FROM users u INNER JOIN persons p ON p.id = u.person_id '
        . 'WHERE p.document_number = :userDocumentNumber) AS users_count, '
        . '(SELECT COUNT(*) FROM families WHERE display_name = :familyDisplayName) AS families_count, '
        . '(SELECT COUNT(*) FROM family_representatives fr INNER JOIN families f ON f.id = fr.family_id '
        . 'WHERE f.display_name = :membershipDisplayName) AS memberships_count'
    );
    $representativeRollbackCounts->execute([
        ':personDocumentNumber' => 'COMPOSITE-REP-ROLLBACK',
        ':representativeDocumentNumber' => 'COMPOSITE-REP-ROLLBACK',
        ':userDocumentNumber' => 'COMPOSITE-REP-ROLLBACK',
        ':familyDisplayName' => 'MariaDB Composite Representative Rollback',
        ':membershipDisplayName' => 'MariaDB Composite Representative Rollback',
    ]);
    $representativeRollbackRows = $representativeRollbackCounts->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeRollbackRejected
        && $representativeRollbackRows !== false
        && (int) $representativeRollbackRows['persons_count'] === 0
        && (int) $representativeRollbackRows['representatives_count'] === 0
        && (int) $representativeRollbackRows['users_count'] === 0
        && (int) $representativeRollbackRows['families_count'] === 0
        && (int) $representativeRollbackRows['memberships_count'] === 0
        && !$connectionA->inTransaction(),
        'Composite Representative failure did not roll back every inserted row.'
    );

    $phase4RepresentativeInput = static function (
        string $documentNumber,
        string $password,
        string $displayName,
        int $relationshipTypeId,
    ): CreateRepresentativeFamilyInput {
        return new CreateRepresentativeFamilyInput(
            firstName: 'E015',
            middleName: 'MariaDB',
            firstSurname: 'Representative',
            secondSurname: 'Rollback',
            documentTypeId: 1,
            documentNumber: $documentNumber,
            birthDate: new DateTimeImmutable('1988-07-08', new DateTimeZone('UTC')),
            sexId: 1,
            maritalStatusId: null,
            educationLevelId: null,
            email: strtolower($documentNumber) . '@example.test',
            mobilePhone: null,
            landlinePhone: null,
            personStatus: PersonStatus::Active,
            occupation: null,
            companyName: null,
            position: null,
            workPhone: null,
            workEmail: null,
            representativeStatus: RepresentativeStatus::Active,
            initialPassword: $password,
            userStatus: UserStatus::Active,
            displayName: $displayName,
            familyStatus: FamilyStatus::Active,
            relationshipTypeId: $relationshipTypeId,
            startedAt: new DateTimeImmutable('2026-08-10 12:13:14', new DateTimeZone('UTC')),
        );
    };
    $phase4CreationCounts = static function (
        PDO $connection,
        string $documentNumber,
        string $displayName,
    ): array {
        $statement = $connection->prepare(
            'SELECT '
            . '(SELECT COUNT(*) FROM persons WHERE document_number = :personDocument) AS persons_count, '
            . '(SELECT COUNT(*) FROM representatives r INNER JOIN persons p ON p.id = r.person_id '
            . 'WHERE p.document_number = :representativeDocument) AS representatives_count, '
            . '(SELECT COUNT(*) FROM users u INNER JOIN persons p ON p.id = u.person_id '
            . 'WHERE p.document_number = :userDocument) AS users_count, '
            . '(SELECT COUNT(*) FROM families WHERE display_name = :familyDisplayName) AS families_count, '
            . '(SELECT COUNT(*) FROM family_representatives fr INNER JOIN families f ON f.id = fr.family_id '
            . 'WHERE f.display_name = :membershipDisplayName) AS memberships_count'
        );
        $statement->execute([
            ':personDocument' => $documentNumber,
            ':representativeDocument' => $documentNumber,
            ':userDocument' => $documentNumber,
            ':familyDisplayName' => $displayName,
            ':membershipDisplayName' => $displayName,
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $assertPhase4NoOrphans = static function (array $counts, string $scenario): void {
        assertIntegration(
            $counts !== []
            && (int) $counts['persons_count'] === 0
            && (int) $counts['representatives_count'] === 0
            && (int) $counts['users_count'] === 0
            && (int) $counts['families_count'] === 0
            && (int) $counts['memberships_count'] === 0,
            $scenario . ' left a Person, Representative, User, Family or membership orphan.'
        );
    };

    $invalidPasswordDocument = 'E015-PHASE4-PASSWORD';
    $invalidPasswordFamily = 'E015 Phase 4 Password Rollback';
    $invalidPasswordRejected = false;
    try {
        $representativeFlow->handle(
            $phase4RepresentativeInput(
                $invalidPasswordDocument,
                'four',
                $invalidPasswordFamily,
                $generatedRelationshipTypeId,
            ),
            $compositeToday,
        );
    } catch (InvalidRepresentativePassword) {
        $invalidPasswordRejected = true;
    }
    assertIntegration($invalidPasswordRejected, 'E015 invalid password was not rejected.');
    $assertPhase4NoOrphans(
        $phase4CreationCounts($identity, $invalidPasswordDocument, $invalidPasswordFamily),
        'E015 invalid-password rollback',
    );

    $adminLoginCountBefore = (int) $identity->query(
        "SELECT COUNT(*) FROM users WHERE normalized_login_identifier = 'admin'"
    )->fetchColumn();
    assertIntegration($adminLoginCountBefore === 1, 'E015 duplicate-login probe requires seeded admin login.');
    $duplicateLoginDocument = 'admin';
    $duplicateLoginFamily = 'E015 Phase 4 Duplicate Login Rollback';
    $duplicateLoginRejected = false;
    try {
        $representativeFlow->handle(
            $phase4RepresentativeInput(
                $duplicateLoginDocument,
                'valid-secret',
                $duplicateLoginFamily,
                $generatedRelationshipTypeId,
            ),
            $compositeToday,
        );
    } catch (RepresentativeLoginIdentifierAlreadyUsed) {
        $duplicateLoginRejected = true;
    }
    assertIntegration(
        $duplicateLoginRejected
        && (int) $identity->query(
            "SELECT COUNT(*) FROM users WHERE normalized_login_identifier = 'admin'"
        )->fetchColumn() === $adminLoginCountBefore,
        'E015 duplicate derived login was not rejected without changing the existing User.'
    );
    $assertPhase4NoOrphans(
        $phase4CreationCounts($identity, $duplicateLoginDocument, $duplicateLoginFamily),
        'E015 duplicate-login rollback',
    );

    $familyFailureDocument = 'E015-PHASE4-FAMILY';
    $familyFailureName = 'E015 Phase 4 Family Rollback';
    $familyFailureRejected = false;
    try {
        $representativeFlow->handle(
            $phase4RepresentativeInput(
                $familyFailureDocument,
                'valid-secret',
                $familyFailureName,
                999999999,
            ),
            $compositeToday,
        );
    } catch (RelationshipTypeNotFound) {
        $familyFailureRejected = true;
    }
    assertIntegration($familyFailureRejected, 'E015 Family-stage failure was not propagated.');
    $assertPhase4NoOrphans(
        $phase4CreationCounts($identity, $familyFailureDocument, $familyFailureName),
        'E015 post-User Family rollback',
    );
    assertIntegration(!$connectionA->inTransaction(), 'E015 Phase 4 left a transaction active.');

    $studentFlow = new CreateStudentInFamily(
        $transactions,
        new GetFamily($familyRepository),
        new \App\Family\Application\Orchestration\StudentFamilyCoordinator(
            $createPerson,
            new \App\Person\Application\GetPerson($personRepository),
            $createStudent,
            new \App\Student\Application\GetStudent($studentRepository),
            new AddStudentToFamily($familyRepository, $studentRepository),
        ),
    );
    $studentFlowOutput = $studentFlow->handle(
        new CreateStudentInFamilyInput(
            familyId: $generatedFamilyId->value(),
            firstName: 'Composite',
            middleName: 'MariaDB',
            firstSurname: 'Student',
            secondSurname: 'Success',
            documentTypeId: 1,
            documentNumber: 'COMPOSITE-STUDENT-SUCCESS',
            birthDate: new DateTimeImmutable('2015-06-07', new DateTimeZone('UTC')),
            sexId: 1,
            maritalStatusId: null,
            educationLevelId: null,
            email: null,
            mobilePhone: null,
            landlinePhone: null,
            personStatus: PersonStatus::Active,
            institutionalCode: 'COMPOSITE-STUDENT-SUCCESS',
            admissionDate: new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            studentStatus: StudentStatus::Active,
            startedAt: new DateTimeImmutable('2026-08-10 13:14:15+02:00'),
        ),
        $compositeToday,
    );
    $studentFlowRow = $identity->prepare(
        'SELECT p.id AS person_id, s.id AS student_id, fs.id AS membership_id, '
        . 'fs.family_id, fs.student_id AS membership_student_id, fs.ended_at '
        . 'FROM persons p INNER JOIN students s ON s.person_id = p.id '
        . 'INNER JOIN family_students fs ON fs.student_id = s.id '
        . 'WHERE p.document_number = :documentNumber AND s.institutional_code = :institutionalCode'
    );
    $studentFlowRow->execute([
        ':documentNumber' => 'COMPOSITE-STUDENT-SUCCESS',
        ':institutionalCode' => 'COMPOSITE-STUDENT-SUCCESS',
    ]);
    $studentPhysical = $studentFlowRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $studentPhysical !== false
        && (int) $studentPhysical['person_id'] === $studentFlowOutput->person->id
        && (int) $studentPhysical['student_id'] === $studentFlowOutput->student->id
        && (int) $studentPhysical['membership_id'] > 0
        && (int) $studentPhysical['family_id'] === $generatedFamilyId->value()
        && (int) $studentPhysical['membership_student_id'] === $studentFlowOutput->student->id
        && $studentPhysical['ended_at'] === null
        && count($studentFlowOutput->family->students) === 2
        && !$connectionA->inTransaction(),
        'Composite Student flow did not commit Person, role and active Family membership with history.'
    );

    $studentRollbackFailure = new RuntimeException('simulated physical FamilyStudent restriction');
    $failingFamilyRepository = new class(
        $familyRepository,
        $studentRollbackFailure,
    ) implements FamilyRepository {
        public function __construct(
            private readonly FamilyRepository $delegate,
            private readonly Throwable $failure,
        ) {
        }

        public function findById(FamilyId $id): ?Family
        {
            return $this->delegate->findById($id);
        }

        public function findByIdForUpdate(FamilyId $id): ?Family
        {
            return $this->delegate->findByIdForUpdate($id);
        }

        public function findByCode(\App\Family\Domain\ValueObject\FamilyCode $familyCode): ?Family
        {
            return $this->delegate->findByCode($familyCode);
        }

        public function findByCodeForUpdate(\App\Family\Domain\ValueObject\FamilyCode $familyCode): ?Family
        {
            return $this->delegate->findByCodeForUpdate($familyCode);
        }

        public function findActiveByRepresentativeId(
            FamilyRepresentativeReference $representativeId,
        ): array {
            return $this->delegate->findActiveByRepresentativeId($representativeId);
        }

        public function findActiveByStudentId(FamilyStudentReference $studentId): ?Family
        {
            return $this->delegate->findActiveByStudentId($studentId);
        }

        public function findActiveByStudentIdForUpdate(FamilyStudentReference $studentId): ?Family
        {
            return $this->delegate->findActiveByStudentIdForUpdate($studentId);
        }

        public function findActiveByRepresentativeAndFamilyForUpdate(
            FamilyRepresentativeReference $representativeId,
            FamilyId $familyId,
        ): ?Family {
            return $this->delegate->findActiveByRepresentativeAndFamilyForUpdate($representativeId, $familyId);
        }

        public function save(Family $family): Family
        {
            $this->delegate->save($family);
            throw $this->failure;
        }
    };
    $studentRollbackFlow = new CreateStudentInFamily(
        $transactions,
        new GetFamily($failingFamilyRepository),
        new \App\Family\Application\Orchestration\StudentFamilyCoordinator(
            $createPerson,
            new \App\Person\Application\GetPerson($personRepository),
            $createStudent,
            new \App\Student\Application\GetStudent($studentRepository),
            new AddStudentToFamily($failingFamilyRepository, $studentRepository),
        ),
    );
    $familyStateBeforeStudentRollback = mariaDbFamilyPhysicalState(
        $identity,
        $generatedFamilyId->value(),
    );
    $caughtStudentRollbackFailure = null;
    try {
        $studentRollbackFlow->handle(
            new CreateStudentInFamilyInput(
                familyId: $generatedFamilyId->value(),
                firstName: 'Composite',
                middleName: 'MariaDB',
                firstSurname: 'Student',
                secondSurname: 'Rollback',
                documentTypeId: 1,
                documentNumber: 'COMPOSITE-STUDENT-ROLLBACK',
                birthDate: new DateTimeImmutable('2014-07-08', new DateTimeZone('UTC')),
                sexId: 1,
                maritalStatusId: null,
                educationLevelId: null,
                email: null,
                mobilePhone: null,
                landlinePhone: null,
                personStatus: PersonStatus::Active,
                institutionalCode: 'COMPOSITE-STUDENT-ROLLBACK',
                admissionDate: new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
                studentStatus: StudentStatus::Active,
                startedAt: new DateTimeImmutable('2026-08-10 14:15:16', new DateTimeZone('UTC')),
            ),
            $compositeToday,
        );
    } catch (Throwable $exception) {
        $caughtStudentRollbackFailure = $exception;
    }
    $studentRollbackCounts = $identity->prepare(
        'SELECT '
        . '(SELECT COUNT(*) FROM persons WHERE document_number = :documentNumber) AS persons_count, '
        . '(SELECT COUNT(*) FROM students WHERE institutional_code = :studentCode) AS students_count, '
        . '(SELECT COUNT(*) FROM family_students fs INNER JOIN students s ON s.id = fs.student_id '
        . 'WHERE s.institutional_code = :membershipCode) AS memberships_count'
    );
    $studentRollbackCounts->execute([
        ':documentNumber' => 'COMPOSITE-STUDENT-ROLLBACK',
        ':studentCode' => 'COMPOSITE-STUDENT-ROLLBACK',
        ':membershipCode' => 'COMPOSITE-STUDENT-ROLLBACK',
    ]);
    $studentRollbackRows = $studentRollbackCounts->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $caughtStudentRollbackFailure === $studentRollbackFailure
        && $studentRollbackRows !== false
        && (int) $studentRollbackRows['persons_count'] === 0
        && (int) $studentRollbackRows['students_count'] === 0
        && (int) $studentRollbackRows['memberships_count'] === 0
        && mariaDbFamilyPhysicalState($identity, $generatedFamilyId->value())
            === $familyStateBeforeStudentRollback
        && !$connectionA->inTransaction(),
        'Composite Student failure did not restore Person, Student and existing Family state.'
    );

    $representativeUserPerson = $personRepository->save(new Person(
        null,
        new PersonalName('E007', null, 'Representative', 'User'),
        new Identification(1, 'E007-LOGIN-OLD'),
        new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
        1,
        null,
        null,
        new ContactInformation('e007-representative@example.test', null, null),
        PersonStatus::Active,
        $personToday,
    ));
    $representativeUserPersonId = $representativeUserPerson->id();
    assertIntegration(
        $representativeUserPersonId !== null && $representativeUserPersonId->value() > 0,
        'MariaDB did not generate the E007 Representative Person identity.'
    );
    $representativeUserRole = $representativeRepository->save(new Representative(
        null,
        new RepresentativePersonId($representativeUserPersonId->value()),
        null,
        RepresentativeStatus::Active,
    ));
    $representativeUserRoleId = $representativeUserRole->id();
    assertIntegration(
        $representativeUserRoleId !== null && $representativeUserRoleId->value() > 0,
        'MariaDB did not generate the E007 Representative role identity.'
    );

    $missingEmailProvisioningRejected = false;
    try {
        $createRepresentativeUser->handle(new CreateRepresentativeUserInput(
            $generatedRepresentativeId->value(),
            'abcde',
            UserStatus::Active,
        ));
    } catch (RepresentativeRequiresContactEmail) {
        $missingEmailProvisioningRejected = true;
    }
    assertIntegration(
        $missingEmailProvisioningRejected
        && $representativeUsers->findByPersonId(
            new UserPersonId($generatedPersonId->value())
        ) === null,
        'Representative User provisioning accepted historical Representative data without personal email.'
    );
    $initialRepresentativePassword = 'abcde';
    $representativeUserOutput = $createRepresentativeUser->handle(
        new CreateRepresentativeUserInput(
            $representativeUserRoleId->value(),
            $initialRepresentativePassword,
            UserStatus::Active,
        )
    );
    $generatedRepresentativeUserId = $representativeUserOutput->userId;
    $persistedRepresentativeUser = $representativeUsers->findByPersonId(
        new UserPersonId($representativeUserPersonId->value())
    );
    $representativeUserRow = $identity->prepare(
        'SELECT u.id, u.person_id, u.login_identifier, u.normalized_login_identifier, '
        . 'u.password_hash, u.failed_login_attempts, u.locked_at, u.last_access_at, '
        . 's.code AS status_code, st.code AS status_type_code '
        . 'FROM users u INNER JOIN statuses s ON s.id = u.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id WHERE u.id = :id'
    );
    $representativeUserRow->execute([':id' => $generatedRepresentativeUserId]);
    $representativeUserPhysical = $representativeUserRow->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $generatedRepresentativeUserId > 0
        && $persistedRepresentativeUser?->id()?->value() === $generatedRepresentativeUserId
        && $persistedRepresentativeUser?->personId()->value() === $representativeUserPersonId->value()
        && $persistedRepresentativeUser?->loginIdentifier()->value() === 'e007-login-old'
        && $representativeUserPhysical !== false
        && (int) $representativeUserPhysical['person_id'] === $representativeUserPersonId->value()
        && $representativeUserPhysical['login_identifier'] === 'e007-login-old'
        && $representativeUserPhysical['normalized_login_identifier'] === 'e007-login-old'
        && $representativeUserPhysical['password_hash'] !== $initialRepresentativePassword
        && $representativePasswordHasher->verify(
            $initialRepresentativePassword,
            (string) $representativeUserPhysical['password_hash'],
        )
        && (int) $representativeUserPhysical['failed_login_attempts'] === 0
        && $representativeUserPhysical['locked_at'] === null
        && $representativeUserPhysical['last_access_at'] === null
        && $representativeUserPhysical['status_type_code'] === 'USER_STATUS'
        && $representativeUserPhysical['status_code'] === 'ACTIVE',
        'Representative User provisioning did not use MariaDB identity hashing exact status or complete reload.'
    );

    $perPersonUserUniqueRejected = false;
    try {
        $representativeUsers->save(new User(
            null,
            new UserPersonId($representativeUserPersonId->value()),
            new LoginIdentifier('e007-other-login'),
            new PasswordHash($representativePasswordHasher->hash('other-password')),
            UserStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $perPersonUserUniqueRejected = true;
    }
    assertIntegration(
        $perPersonUserUniqueRejected,
        'MariaDB did not enforce one User per Person.'
    );

    $normalizedLoginUniqueRejected = false;
    try {
        $representativeUsers->save(new User(
            null,
            new UserPersonId($secondGeneratedPersonId->value()),
            new LoginIdentifier('E007-LOGIN-OLD'),
            new PasswordHash($representativePasswordHasher->hash('other-password')),
            UserStatus::Active,
        ));
    } catch (PDOException $exception) {
        if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23000') {
            throw $exception;
        }
        $normalizedLoginUniqueRejected = true;
    }
    assertIntegration(
        $normalizedLoginUniqueRejected,
        'MariaDB did not enforce global normalized Representative login uniqueness.'
    );

    $authenticationStateInstant = new DateTimeImmutable('2026-08-01 08:09:10', new DateTimeZone('UTC'));
    $persistedRepresentativeUser->recordSuccessfulAuthentication($authenticationStateInstant);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $persistedRepresentativeUser->recordFailedLogin(
            $authenticationStateInstant->modify(sprintf('+%d minutes', $attempt)),
            5,
        );
    }
    $representativeUsers->save($persistedRepresentativeUser);
    $beforePasswordChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    $newRepresentativePassword = '12345';
    $changedPasswordOutput = (new ChangeRepresentativeUserPassword(
        $representativeRepository,
        $representativeUsers,
        $representativePasswordHasher,
        $representativePasswordPolicy,
    ))->handle(new ChangeRepresentativeUserPasswordInput(
        $representativeUserRoleId->value(),
        $newRepresentativePassword,
    ));
    $afterPasswordChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    assertIntegration(
        $changedPasswordOutput->userId === $generatedRepresentativeUserId
        && $afterPasswordChange?->personId()->value() === $beforePasswordChange?->personId()->value()
        && $afterPasswordChange?->loginIdentifier()->value()
            === $beforePasswordChange?->loginIdentifier()->value()
        && $afterPasswordChange?->status() === $beforePasswordChange?->status()
        && $afterPasswordChange?->failedLoginAttempts()
            === $beforePasswordChange?->failedLoginAttempts()
        && $afterPasswordChange?->lockedAt()?->getTimestamp()
            === $beforePasswordChange?->lockedAt()?->getTimestamp()
        && $afterPasswordChange?->lastAccessAt()?->getTimestamp()
            === $beforePasswordChange?->lastAccessAt()?->getTimestamp()
        && $afterPasswordChange !== null
        && $representativePasswordHasher->verify(
            $newRepresentativePassword,
            $afterPasswordChange->passwordHash()->value(),
        )
        && !$representativePasswordHasher->verify(
            $initialRepresentativePassword,
            $afterPasswordChange->passwordHash()->value(),
        ),
        'Administrative password change did not preserve Representative User authentication state.'
    );

    $updateRepresentativePerson = new UpdatePersonWithRepresentativeUserSync(
        new UpdatePerson($personRepository),
        $personRepository,
        $representativeUsers,
        $representativeRepository,
        $transactions,
    );
    $representativeWithoutUserPerson = $personRepository->save(new Person(
        null,
        new PersonalName('E007', null, 'Representative', 'Without User'),
        new Identification(1, 'E007-REP-NO-USER'),
        new DateTimeImmutable('1988-11-12', new DateTimeZone('UTC')),
        1,
        null,
        null,
        new ContactInformation('representative-without-user@example.test', null, null),
        PersonStatus::Active,
        $personToday,
    ));
    $representativeWithoutUserPersonId = $representativeWithoutUserPerson->id();
    assertIntegration(
        $representativeWithoutUserPersonId !== null,
        'MariaDB did not persist the Representative Person without User.'
    );
    $representativeRepository->save(new Representative(
        null,
        new RepresentativePersonId($representativeWithoutUserPersonId->value()),
        null,
        RepresentativeStatus::Active,
    ));
    $representativeWithoutUserEmailRemovalRejected = false;
    try {
        $updateRepresentativePerson->handle(new UpdatePersonInput(
            $representativeWithoutUserPersonId->value(),
            'E007',
            null,
            'Representative',
            'Without User',
            1,
            'E007-REP-NO-USER',
            new DateTimeImmutable('1988-11-12', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        ), $personToday);
    } catch (RepresentativeRequiresContactEmail) {
        $representativeWithoutUserEmailRemovalRejected = true;
    }
    assertIntegration(
        $representativeWithoutUserEmailRemovalRejected
        && $personRepository->findById(
            $representativeWithoutUserPersonId
        )?->contactInformation()?->email() === 'representative-without-user@example.test'
        && $representativeUsers->findByPersonId(
            new UserPersonId($representativeWithoutUserPersonId->value())
        ) === null,
        'Representative without User lost the required Person contact email.'
    );

    $beforeRepresentativeEmailChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    $representativeEmailUpdate = $updateRepresentativePerson->handle(
        new UpdatePersonInput(
            $representativeUserPersonId->value(),
            'E007',
            null,
            'Representative',
            'User',
            1,
            'E007-LOGIN-OLD',
            new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
            1,
            null,
            null,
            'updated-representative@example.test',
            null,
            null,
            PersonStatus::Active,
        ),
        $personToday,
    );
    $afterRepresentativeEmailChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    assertIntegration(
        $representativeEmailUpdate->email === 'updated-representative@example.test'
        && $beforeRepresentativeEmailChange !== null
        && $afterRepresentativeEmailChange !== null
        && $afterRepresentativeEmailChange->id()?->value()
            === $beforeRepresentativeEmailChange->id()?->value()
        && $afterRepresentativeEmailChange->personId()->value()
            === $beforeRepresentativeEmailChange->personId()->value()
        && $afterRepresentativeEmailChange->loginIdentifier()->value()
            === $beforeRepresentativeEmailChange->loginIdentifier()->value()
        && $afterRepresentativeEmailChange->passwordHash()->value()
            === $beforeRepresentativeEmailChange->passwordHash()->value()
        && $afterRepresentativeEmailChange->status() === $beforeRepresentativeEmailChange->status()
        && $afterRepresentativeEmailChange->failedLoginAttempts()
            === $beforeRepresentativeEmailChange->failedLoginAttempts()
        && $afterRepresentativeEmailChange->lockedAt()?->getTimestamp()
            === $beforeRepresentativeEmailChange->lockedAt()?->getTimestamp()
        && $afterRepresentativeEmailChange->lastAccessAt()?->getTimestamp()
            === $beforeRepresentativeEmailChange->lastAccessAt()?->getTimestamp(),
        'Representative email update changed User identity login password or authentication state.'
    );

    $representativeUserEmailRemovalRejected = false;
    try {
        $updateRepresentativePerson->handle(new UpdatePersonInput(
            $representativeUserPersonId->value(),
            'E007',
            null,
            'Representative',
            'User',
            1,
            'E007-LOGIN-OLD',
            new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
            1,
            null,
            null,
            null,
            null,
            null,
            PersonStatus::Active,
        ), $personToday);
    } catch (RepresentativeRequiresContactEmail) {
        $representativeUserEmailRemovalRejected = true;
    }
    assertIntegration(
        $representativeUserEmailRemovalRejected
        && $personRepository->findById(
            $representativeUserPersonId
        )?->contactInformation()?->email() === 'updated-representative@example.test'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->passwordHash()->value() === $afterRepresentativeEmailChange->passwordHash()->value(),
        'Representative User flow removed personal email or changed persisted authentication state.'
    );

    $beforeDocumentChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    $updatedRepresentativePerson = $updateRepresentativePerson->handle(
        new UpdatePersonInput(
            $representativeUserPersonId->value(),
            'E007',
            null,
            'Representative',
            'User',
            1,
            'E007-LOGIN-NEW',
            new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
            1,
            null,
            null,
            'updated-representative@example.test',
            null,
            null,
            PersonStatus::Active,
        ),
        $personToday,
    );
    $afterDocumentChange = $representativeUsers->findById(
        new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
    );
    assertIntegration(
        $updatedRepresentativePerson->documentNumber === 'E007-LOGIN-NEW'
        && $representativeUsers->findByLoginIdentifier(
            new LoginIdentifier('E007-LOGIN-NEW')
        )?->id()?->value() === $generatedRepresentativeUserId
        && $representativeUsers->findByLoginIdentifier(
            new LoginIdentifier('E007-LOGIN-OLD')
        ) === null
        && $afterDocumentChange?->id()?->value() === $beforeDocumentChange?->id()?->value()
        && $afterDocumentChange?->personId()->value()
            === $beforeDocumentChange?->personId()->value()
        && $afterDocumentChange?->passwordHash()->value()
            === $beforeDocumentChange?->passwordHash()->value()
        && $afterDocumentChange?->status() === $beforeDocumentChange?->status()
        && $afterDocumentChange?->failedLoginAttempts()
            === $beforeDocumentChange?->failedLoginAttempts()
        && $afterDocumentChange?->lockedAt()?->getTimestamp()
            === $beforeDocumentChange?->lockedAt()?->getTimestamp()
        && $afterDocumentChange?->lastAccessAt()?->getTimestamp()
            === $beforeDocumentChange?->lastAccessAt()?->getTimestamp(),
        'DocumentNumber and Representative login did not synchronize while preserving User state.'
    );

    $identificationRemovalRejected = false;
    try {
        $updateRepresentativePerson->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007',
                null,
                'Representative',
                'User',
                null,
                null,
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                'updated-representative@example.test',
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (RepresentativeUserRequiresIdentification) {
        $identificationRemovalRejected = true;
    }
    assertIntegration(
        $identificationRemovalRejected
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new',
        'Representative User allowed removal of Person Identification.'
    );

    $loginCollisionRejected = false;
    try {
        $updateRepresentativePerson->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007',
                null,
                'Representative',
                'User',
                1,
                'ADMIN',
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                'updated-representative@example.test',
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (RepresentativeLoginIdentifierAlreadyUsed) {
        $loginCollisionRejected = true;
    }
    assertIntegration(
        $loginCollisionRejected
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new',
        'Representative login collision did not leave Person and User unchanged.'
    );

    $postPersonFailure = new RuntimeException('simulated E007 User save failure');
    $failingRepresentativeUsers = new class(
        $representativeUsers,
        $postPersonFailure,
    ) implements UserRepository {
        public function __construct(
            private readonly UserRepository $delegate,
            private readonly Throwable $failure,
        ) {
        }

        public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
        {
            return $this->delegate->findByLoginIdentifier($identifier);
        }

        public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
        {
            return $this->delegate->findByLoginIdentifierForUpdate($identifier);
        }

        public function findById(\App\IdentityAccess\Domain\ValueObject\UserId $id): ?User
        {
            return $this->delegate->findById($id);
        }

        public function findByPersonId(UserPersonId $personId): ?User
        {
            return $this->delegate->findByPersonId($personId);
        }

        public function save(User $user): User
        {
            throw $this->failure;
        }
    };
    $failingDocumentUpdate = new UpdatePersonWithRepresentativeUserSync(
        new UpdatePerson($personRepository),
        $personRepository,
        $failingRepresentativeUsers,
        $representativeRepository,
        $transactions,
    );
    $caughtPostPersonFailure = null;
    try {
        $failingDocumentUpdate->handle(
            new UpdatePersonInput(
                $representativeUserPersonId->value(),
                'E007 Partial',
                null,
                'Representative',
                'User',
                1,
                'E007-ROLLBACK',
                new DateTimeImmutable('1984-09-10', new DateTimeZone('UTC')),
                1,
                null,
                null,
                'updated-representative@example.test',
                null,
                null,
                PersonStatus::Active,
            ),
            $personToday,
        );
    } catch (Throwable $exception) {
        $caughtPostPersonFailure = $exception;
    }
    assertIntegration(
        $caughtPostPersonFailure === $postPersonFailure
        && $personRepository->findById($representativeUserPersonId)?->personalName()->firstName()
            === 'E007'
        && $personRepository->findById($representativeUserPersonId)?->identification()?->documentNumber()
            === 'E007-LOGIN-NEW'
        && $representativeUsers->findById(
            new \App\IdentityAccess\Domain\ValueObject\UserId($generatedRepresentativeUserId)
        )?->loginIdentifier()->value() === 'e007-login-new'
        && !$connectionA->inTransaction(),
        'Failure after Person update did not roll back both Representative login states.'
    );

    $authenticationSession = new class implements SessionManager {
        public ?int $userId = null;
        /** @var array<string, mixed> */
        private array $values = [];

        public function regenerateForUser(int $userId): void
        {
            unset($this->values['representative_family_context_id']);
            $this->userId = $userId;
        }

        public function authenticatedUserId(): ?int
        {
            return $this->userId;
        }

        public function put(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function pull(string $key, mixed $default = null): mixed
        {
            $value = $this->values[$key] ?? $default;
            unset($this->values[$key]);

            return $value;
        }

        public function remove(string $key): void
        {
            unset($this->values[$key]);
        }

        public function destroy(): void
        {
            $this->userId = null;
            $this->values = [];
        }
    };
    $authenticationEvents = new class implements SecurityEventLogger {
        public function record(string $event): void
        {
        }
    };
    $authenticationClock = new class(
        new DateTimeImmutable('2026-08-01 08:30:00', new DateTimeZone('UTC'))
    ) implements Clock {
        public function __construct(private readonly DateTimeImmutable $now)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
    $authenticateRepresentative = new AuthenticateUser(
        $representativeUsers,
        $representativePasswordHasher,
        $authenticationSession,
        new PdoTransactionManager($managerA),
        $authenticationClock,
        $authenticationEvents,
        new AuthenticationPolicy(5, 900),
    );
    $oldLoginResult = $authenticateRepresentative->handle(
        'E007-LOGIN-OLD',
        $newRepresentativePassword,
    );
    $newLoginResult = $authenticateRepresentative->handle(
        'E007-LOGIN-NEW',
        $newRepresentativePassword,
    );
    assertIntegration(
        !$oldLoginResult->isSuccessful()
        && $newLoginResult->isSuccessful()
        && $authenticationSession->userId === $generatedRepresentativeUserId,
        'Representative authentication did not move exclusively to the synchronized DocumentNumber.'
    );

    $representativeAccessUserState = $identity->prepare(
        'SELECT * FROM users WHERE id = :id'
    );
    $representativeAccessUserState->execute([':id' => $generatedRepresentativeUserId]);
    $representativeAccessUserBefore = $representativeAccessUserState->fetch(PDO::FETCH_ASSOC);
    $representativeAccessRoleState = $identity->prepare(
        'SELECT * FROM representatives WHERE id = :id'
    );
    $representativeAccessRoleState->execute([':id' => $representativeUserRoleId->value()]);
    $representativeAccessRoleBefore = $representativeAccessRoleState->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $representativeAccessUserBefore !== false && $representativeAccessRoleBefore !== false,
        'Representative access read-only snapshot could not load the persisted identities.'
    );

    $getAuthenticatedRepresentative = new GetAuthenticatedRepresentative(
        new GetAuthenticatedUser($authenticationSession, $representativeUsers),
        $representativeRepository,
    );
    $authenticatedRepresentative = $getAuthenticatedRepresentative->handle();

    $representativeAccessUserState->execute([':id' => $generatedRepresentativeUserId]);
    $representativeAccessUserAfter = $representativeAccessUserState->fetch(PDO::FETCH_ASSOC);
    $representativeAccessRoleState->execute([':id' => $representativeUserRoleId->value()]);
    $representativeAccessRoleAfter = $representativeAccessRoleState->fetch(PDO::FETCH_ASSOC);
    assertIntegration(
        $authenticatedRepresentative?->userId === $generatedRepresentativeUserId
        && $authenticatedRepresentative->personId === $representativeUserPersonId->value()
        && $authenticatedRepresentative->representativeId === $representativeUserRoleId->value()
        && $authenticatedRepresentative->loginIdentifier === 'e007-login-new'
        && $representativeAccessUserAfter === $representativeAccessUserBefore
        && $representativeAccessRoleAfter === $representativeAccessRoleBefore,
        'Authenticated Representative resolution did not preserve exact identity or read-only state.'
    );

    $nonRepresentativeAccessPerson = $personRepository->save(new Person(
        null,
        new PersonalName('Access', null, 'Nonrepresentative', null),
        null,
        new DateTimeImmutable('2002-02-02', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $personToday,
    ));
    $nonRepresentativeAccessPersonId = $nonRepresentativeAccessPerson->id();
    assertIntegration(
        $nonRepresentativeAccessPersonId !== null && $nonRepresentativeAccessPersonId->value() > 0,
        'MariaDB did not generate the non-Representative Person identity for access resolution.'
    );
    $nonRepresentativeAccessUser = $representativeUsers->save(new User(
        null,
        new UserPersonId($nonRepresentativeAccessPersonId->value()),
        new LoginIdentifier('E007-ACCESS-NON-REPRESENTATIVE'),
        new PasswordHash($representativePasswordHasher->hash('access-resolution-probe')),
        UserStatus::Active,
    ));
    $nonRepresentativeAccessUserId = $nonRepresentativeAccessUser->id();
    assertIntegration(
        $nonRepresentativeAccessUserId !== null && $nonRepresentativeAccessUserId->value() > 0,
        'MariaDB did not generate the non-Representative User identity for access resolution.'
    );
    $authenticationSession->userId = $nonRepresentativeAccessUserId->value();
    assertIntegration(
        $getAuthenticatedRepresentative->handle() === null,
        'Authenticated User without Representative unexpectedly received Representative Access.'
    );
    echo "PASS MySQL authenticated Representative access resolution read-only identity and fail-closed behavior\n";

    $phase4FamilyA = Family::create(
        \Tests\FamilyCodeTestFactory::next(),
        new DisplayName('E007 Phase 4 Family A'),
        FamilyStatus::Inactive,
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-10 09:00:00', new DateTimeZone('UTC')),
    );
    $phase4FamilyA->addRepresentative(
        new FamilyRepresentativeReference($representativeUserRoleId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-10 09:01:00', new DateTimeZone('UTC')),
    );
    $phase4FamilyA = $familyRepository->save($phase4FamilyA);
    $phase4FamilyAId = $phase4FamilyA->id();
    assertIntegration(
        $phase4FamilyAId !== null && $phase4FamilyAId->value() > 0,
        'MariaDB did not generate the first E007 Phase 4 Family identity.'
    );

    $authenticationSession->userId = $generatedRepresentativeUserId;
    $getAuthorizedFamilies = new GetAuthorizedFamilies(
        $getAuthenticatedRepresentative,
        $familyRepository,
    );
    $familyContextSession = new RepresentativeFamilyContextSession($authenticationSession);
    $resolveFamilyContext = new ResolveFamilyContext($getAuthorizedFamilies, $familyContextSession);
    $selectAuthorizedFamily = new SelectAuthorizedFamily(
        $getAuthorizedFamilies,
        $familyContextSession,
    );
    $singleFamilyAccess = $resolveFamilyContext->handle();
    assertIntegration(
        $singleFamilyAccess !== null
        && count($singleFamilyAccess->authorizedFamilies) === 1
        && $singleFamilyAccess->authorizedFamilies[0]->familyId === $phase4FamilyAId->value()
        && $singleFamilyAccess->authorizedFamilies[0]->displayName === 'E007 Phase 4 Family A'
        && $singleFamilyAccess->context?->familyId === $phase4FamilyAId->value()
        && !$singleFamilyAccess->requiresSelection,
        'One active FamilyRepresentative membership did not auto-resolve its exact Family context.'
    );

    $phase4FamilyB = Family::create(
        \Tests\FamilyCodeTestFactory::next(),
        new DisplayName('E007 Phase 4 Family B'),
        FamilyStatus::Active,
        new FamilyRepresentativeReference($secondRepresentativeId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-10 10:00:00', new DateTimeZone('UTC')),
    );
    $phase4FamilyB->addRepresentative(
        new FamilyRepresentativeReference($representativeUserRoleId->value()),
        new RelationshipTypeId($generatedRelationshipTypeId),
        new DateTimeImmutable('2026-08-10 10:01:00', new DateTimeZone('UTC')),
    );
    $phase4FamilyB = $familyRepository->save($phase4FamilyB);
    $phase4FamilyBId = $phase4FamilyB->id();
    assertIntegration(
        $phase4FamilyBId !== null && $phase4FamilyBId->value() > 0,
        'MariaDB did not generate the second E007 Phase 4 Family identity.'
    );

    $phase4StateBeforeReadOnlyAccess = mariaDbRepresentativeFamilyAccessState($identity);
    $familyContextSession->clear();
    $multipleFamilyAccess = $resolveFamilyContext->handle();
    assertIntegration(
        $multipleFamilyAccess !== null
        && array_map(
            static fn ($family): int => $family->familyId,
            $multipleFamilyAccess->authorizedFamilies,
        ) === [$phase4FamilyAId->value(), $phase4FamilyBId->value()]
        && $multipleFamilyAccess->context === null
        && $multipleFamilyAccess->requiresSelection,
        'Two active FamilyRepresentative memberships did not require explicit deterministic selection.'
    );
    $selectedFamilyA = $selectAuthorizedFamily->handle($phase4FamilyAId->value());
    $selectedFamilyB = $selectAuthorizedFamily->handle($phase4FamilyBId->value());
    $otherRepresentativeFamilyRejected = false;
    try {
        $selectAuthorizedFamily->handle($generatedFamilyId->value());
    } catch (FamilyContextNotAuthorized) {
        $otherRepresentativeFamilyRejected = true;
    }
    $phase4StateAfterReadOnlyAccess = mariaDbRepresentativeFamilyAccessState($identity);
    assertIntegration(
        $selectedFamilyA->familyId === $phase4FamilyAId->value()
        && $selectedFamilyA->representativeId === $representativeUserRoleId->value()
        && $selectedFamilyB->familyId === $phase4FamilyBId->value()
        && $selectedFamilyB->representativeId === $representativeUserRoleId->value()
        && $otherRepresentativeFamilyRejected
        && $familyContextSession->selectedFamilyId() === $phase4FamilyBId->value()
        && $phase4StateAfterReadOnlyAccess === $phase4StateBeforeReadOnlyAccess,
        'Family selection change authorization or read-only persistence state was not exact.'
    );

    $phase4FamilyB->endRepresentativeMembership(
        new FamilyRepresentativeReference($representativeUserRoleId->value()),
        new DateTimeImmutable('2026-08-10 11:00:00', new DateTimeZone('UTC')),
    );
    $familyRepository->save($phase4FamilyB);
    $phase4StateAfterMembershipEnd = mariaDbRepresentativeFamilyAccessState($identity);
    $staleFamilyAccess = $resolveFamilyContext->handle();
    $adminUser = $representativeUsers->findByLoginIdentifier(new LoginIdentifier('admin'));
    $adminUserId = $adminUser?->id();
    assertIntegration(
        $adminUserId !== null,
        'Existing administrator identity was unavailable for Family context compatibility.'
    );
    $authenticationSession->userId = $adminUserId->value();
    $adminFamilyAccess = $resolveFamilyContext->handle();
    $phase4StateAfterStaleAndAdminResolution = mariaDbRepresentativeFamilyAccessState($identity);
    assertIntegration(
        $staleFamilyAccess !== null
        && count($staleFamilyAccess->authorizedFamilies) === 1
        && $staleFamilyAccess->authorizedFamilies[0]->familyId === $phase4FamilyAId->value()
        && $staleFamilyAccess->context?->familyId === $phase4FamilyAId->value()
        && !$staleFamilyAccess->requiresSelection
        && $adminFamilyAccess === null
        && $familyContextSession->selectedFamilyId() === null
        && $phase4StateAfterStaleAndAdminResolution === $phase4StateAfterMembershipEnd,
        'Stale historical membership or administrator Family context did not fail closed without writes.'
    );
    echo "PASS MySQL Representative Family context authorization selection stale invalidation and read-only behavior\n";

    assertIntegration(
        $representativePasswordHasher->verify('DisposableAdminPassword', $hash)
        && $representativeUsers->findByLoginIdentifier(new LoginIdentifier('admin')) !== null,
        'Existing administrator credential compatibility was not preserved.'
    );

    $managerB = new ConnectionManager(new ConnectionFactory(), $databaseConfig);
    $connectionB = $managerB->connection();
    assertIntegration(
        $connectionA->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00',
        'ConnectionFactory did not establish the UTC SQL convention.'
    );

    $concurrencyRequirementsA = new PdoAcknowledgementRequirementRepository($managerA);
    $concurrencyRequirementsB = new PdoAcknowledgementRequirementRepository($managerB);
    $concurrencyCompletionsA = new PdoRepresentativeAcknowledgementCompletionRepository($managerA);
    $concurrencyCompletionsB = new PdoRepresentativeAcknowledgementCompletionRepository($managerB);
    $insertConcurrencyPeriod = $connectionA->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    $concurrencyPeriodIds = [];
    foreach ([
        ['E009_CONC_UPDATE_FIRST', 'E009 concurrency update first', '2039-08-01', '2040-07-31'],
        ['E009_CONC_COMPLETION_FIRST', 'E009 concurrency completion first', '2040-08-01', '2041-07-31'],
        ['E009_CONC_MULTI', 'E009 concurrency multi Requirement', '2041-08-01', '2042-07-31'],
        ['E009_CONC_ROLLBACK', 'E009 concurrency rollback', '2042-08-01', '2043-07-31'],
        ['E009_CONC_CREATE_SCOPE', 'E009 concurrency Requirement creation first', '2043-08-01', '2044-07-31'],
        ['E009_CONC_COMPLETE_SCOPE', 'E009 concurrency Completion first against creation', '2044-08-01', '2045-07-31'],
        ['E009_CONC_ZERO_SCOPE', 'E009 concurrency zero Requirement Completion', '2045-08-01', '2046-07-31'],
        ['E009_CONC_SCOPE_A', 'E009 concurrency isolated scope A', '2046-08-01', '2047-07-31'],
        ['E009_CONC_SCOPE_B', 'E009 concurrency isolated scope B', '2047-08-01', '2048-07-31'],
        ['E009_CONC_CREATE_ROLLBACK', 'E009 concurrency Requirement creation rollback', '2048-08-01', '2049-07-31'],
    ] as [$code, $name, $startsOn, $endsOn]) {
        $insertConcurrencyPeriod->execute([
            ':code' => $code,
            ':name' => $name,
            ':startsOn' => $startsOn,
            ':endsOn' => $endsOn,
            ':statusId' => $inactiveGeneralStatusId,
        ]);
        $concurrencyPeriodIds[$code] = (int) $connectionA->lastInsertId();
    }
    assertIntegration(
        count(array_filter($concurrencyPeriodIds, static fn (int $id): bool => $id > 0)) === 10
        && count(array_unique($concurrencyPeriodIds)) === 10,
        'MariaDB did not generate the ten isolated E009 concurrency AcademicPeriod identities.'
    );

    $newConcurrencyRequirement = static function (
        PdoAcknowledgementRequirementRepository $repository,
        int $academicPeriodId,
        string $title,
        string $reference,
    ): \App\InstitutionalDocuments\Domain\AcknowledgementRequirement {
        return $repository->save(\App\InstitutionalDocuments\Domain\AcknowledgementRequirement::create(
            new AcknowledgementAcademicPeriodId($academicPeriodId),
            new AcknowledgementRequirementTitle($title),
            new AcknowledgementRequirementUrl('https://example.test/e009/concurrency/' . strtolower($reference)),
            new AcknowledgementOfficialReference($reference),
            AcknowledgementRequirementStatus::Active,
        ));
    };
    $representativeId = $persistenceRepresentativeIds['rollback'];

    $updateFirstPeriodId = $concurrencyPeriodIds['E009_CONC_UPDATE_FIRST'];
    $updateFirstRequirement = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $updateFirstPeriodId,
        'Update-first original title',
        'UPDATE-FIRST',
    );
    $updateFirstRequirementId = $updateFirstRequirement->id();
    assertIntegration($updateFirstRequirementId !== null, 'Update-first Requirement has no identity.');
    $connectionA->beginTransaction();
    $lockedUpdateFirst = $concurrencyRequirementsA->lockForPostUseUpdate($updateFirstRequirementId);
    assertIntegration(
        $lockedUpdateFirst !== null
        && !$concurrencyRequirementsA->hasAcknowledgements($updateFirstRequirementId),
        'Update-first transaction did not lock a Requirement without history.'
    );
    $lockedUpdateFirst->update(
        new AcknowledgementRequirementTitle('Update-first committed title'),
        $lockedUpdateFirst->url(),
        $lockedUpdateFirst->officialReference(),
        false,
    );
    $concurrencyRequirementsA->save($lockedUpdateFirst);
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $updateFirstCompletionBlocked = false;
    try {
        (new CompleteRepresentativeAcknowledgements(
            $concurrencyRequirementsB,
            $concurrencyCompletionsB,
            new PdoTransactionRunner($managerB),
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(
            $representativeId,
            $updateFirstPeriodId,
            [$updateFirstRequirementId->value()],
            new DateTimeImmutable('2037-02-03 15:11:12+00:00'),
        ));
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Update-first Completion failed for a reason other than Requirement lock contention.',
                previous: $exception,
            );
        }
        $updateFirstCompletionBlocked = true;
    }
    assertIntegration(
        $updateFirstCompletionBlocked && !$connectionB->inTransaction(),
        'Completion crossed the Requirement protocol while Update retained the row lock.'
    );
    $connectionA->commit();
    $serializedUpdateFirstCompletion = (new CompleteRepresentativeAcknowledgements(
        $concurrencyRequirementsB,
        $concurrencyCompletionsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CompleteRepresentativeAcknowledgementsInput(
        $representativeId,
        $updateFirstPeriodId,
        [$updateFirstRequirementId->value()],
        new DateTimeImmutable('2037-02-03 15:11:12+00:00'),
    ));
    $updateFirstReloaded = $concurrencyRequirementsA->findById($updateFirstRequirementId);
    assertIntegration(
        ($serializedUpdateFirstCompletion->completionId ?? 0) > 0
        && $serializedUpdateFirstCompletion->acknowledgedRequirementIds === [$updateFirstRequirementId->value()]
        && $updateFirstReloaded?->title()->value() === 'Update-first committed title',
        'Serialized update-first Completion did not use the post-commit Requirement state.'
    );

    $completionFirstPeriodId = $concurrencyPeriodIds['E009_CONC_COMPLETION_FIRST'];
    $completionFirstRequirement = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $completionFirstPeriodId,
        'Completion-first protected title',
        'COMPLETION-FIRST',
    );
    $completionFirstRequirementId = $completionFirstRequirement->id();
    assertIntegration($completionFirstRequirementId !== null, 'Completion-first Requirement has no identity.');
    $connectionB->beginTransaction();
    $concurrencyRequirementsB->lockConfigurationScope(
        new AcknowledgementAcademicPeriodId($completionFirstPeriodId)
    );
    $completionFirstLocked = $concurrencyRequirementsB->lockForCompletion(
        new AcknowledgementAcademicPeriodId($completionFirstPeriodId)
    );
    $completionFirstPersisted = $concurrencyCompletionsB->save(
        RepresentativeAcknowledgementCompletion::complete(
            new AcknowledgementRepresentativeId($representativeId),
            new AcknowledgementAcademicPeriodId($completionFirstPeriodId),
            new DateTimeImmutable('2037-03-03 15:11:12+00:00'),
            $completionFirstLocked,
        )
    );
    assertIntegration(
        ($completionFirstPersisted->id()?->value() ?? 0) > 0,
        'Completion-first transaction did not create its first acknowledgement before commit.'
    );
    $completionFirstUpdate = new UpdateAcknowledgementRequirement(
        $concurrencyRequirementsA,
        new PdoTransactionRunner($managerA),
    );
    $completionFirstUpdateBlocked = false;
    try {
        $connectionA->exec('SET innodb_lock_wait_timeout = 1');
        $completionFirstUpdate->handle(new UpdateAcknowledgementRequirementInput(
            $completionFirstRequirementId->value(),
            $completionFirstPeriodId,
            'Forbidden concurrent title',
            $completionFirstRequirement->url()->value(),
            'COMPLETION-FIRST',
        ));
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Completion-first Update failed for a reason other than Requirement lock contention.',
                previous: $exception,
            );
        }
        $completionFirstUpdateBlocked = true;
    }
    assertIntegration(
        $completionFirstUpdateBlocked && !$connectionA->inTransaction(),
        'Update crossed the Requirement protocol while Completion retained the row lock.'
    );
    $connectionB->commit();

    foreach ([
        ['Forbidden post-use title', 'COMPLETION-FIRST'],
        ['Completion-first protected title', 'FORBIDDEN-REFERENCE'],
    ] as [$title, $reference]) {
        $protectedChangeRejected = false;
        try {
            $completionFirstUpdate->handle(new UpdateAcknowledgementRequirementInput(
                $completionFirstRequirementId->value(),
                $completionFirstPeriodId,
                $title,
                $completionFirstRequirement->url()->value(),
                $reference,
            ));
        } catch (InvalidInstitutionalAcknowledgementState) {
            $protectedChangeRejected = true;
        }
        assertIntegration(
            $protectedChangeRejected,
            'Post-use Requirement Title or OfficialReference change was not rejected after serialization.'
        );
    }
    $mutableUrl = 'https://example.test/e009/concurrency/post-use-url';
    $urlUpdated = $completionFirstUpdate->handle(new UpdateAcknowledgementRequirementInput(
        $completionFirstRequirementId->value(),
        $completionFirstPeriodId,
        'Completion-first protected title',
        $mutableUrl,
        'COMPLETION-FIRST',
    ));
    $deactivatedPostUse = (new DeactivateAcknowledgementRequirement($concurrencyRequirementsA))->handle(
        $completionFirstRequirementId->value(),
        $completionFirstPeriodId,
    );
    $activatedPostUse = (new ActivateAcknowledgementRequirement($concurrencyRequirementsA))->handle(
        $completionFirstRequirementId->value(),
        $completionFirstPeriodId,
    );
    $completionFirstReloaded = $concurrencyRequirementsA->findById($completionFirstRequirementId);
    assertIntegration(
        $urlUpdated->url === $mutableUrl
        && $deactivatedPostUse->status === 'INACTIVE'
        && $activatedPostUse->status === 'ACTIVE'
        && $completionFirstReloaded?->title()->value() === 'Completion-first protected title'
        && $completionFirstReloaded?->officialReference()?->value() === 'COMPLETION-FIRST',
        'Post-use mutable fields or protected Requirement fields lost their approved semantics.'
    );

    $multiPeriodId = $concurrencyPeriodIds['E009_CONC_MULTI'];
    $multiRequirementA = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $multiPeriodId,
        'Multi Requirement A',
        'MULTI-A',
    );
    $multiRequirementB = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $multiPeriodId,
        'Multi Requirement B',
        'MULTI-B',
    );
    $connectionA->beginTransaction();
    $concurrencyRequirementsA->lockConfigurationScope(new AcknowledgementAcademicPeriodId($multiPeriodId));
    $multiLocked = $concurrencyRequirementsA->lockForCompletion(
        new AcknowledgementAcademicPeriodId($multiPeriodId)
    );
    $multiLockedIds = array_map(
        static fn ($requirement): ?int => $requirement->id()?->value(),
        $multiLocked,
    );
    $multiSortedIds = $multiLockedIds;
    sort($multiSortedIds, SORT_NUMERIC);
    assertIntegration(
        $multiLockedIds === $multiSortedIds
        && $multiLockedIds === [$multiRequirementA->id()?->value(), $multiRequirementB->id()?->value()],
        'Multi-Requirement Completion lock order was not deterministic ascending identity.'
    );
    $connectionA->rollBack();

    $rollbackPeriodId = $concurrencyPeriodIds['E009_CONC_ROLLBACK'];
    $rollbackRequirement = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $rollbackPeriodId,
        'Rollback protected title',
        'ROLLBACK',
    );
    $rollbackRequirementId = $rollbackRequirement->id();
    assertIntegration($rollbackRequirementId !== null, 'Rollback Requirement has no identity.');
    $failingConcurrencyCompletions = new class($concurrencyCompletionsA) implements
        RepresentativeAcknowledgementCompletionRepository {
        public function __construct(
            private readonly RepresentativeAcknowledgementCompletionRepository $inner,
        ) {
        }

        public function findByRepresentativeAndAcademicPeriod(
            AcknowledgementRepresentativeId $representativeId,
            AcknowledgementAcademicPeriodId $academicPeriodId,
        ): ?RepresentativeAcknowledgementCompletion {
            return $this->inner->findByRepresentativeAndAcademicPeriod($representativeId, $academicPeriodId);
        }

        public function save(
            RepresentativeAcknowledgementCompletion $completion,
        ): RepresentativeAcknowledgementCompletion {
            $this->inner->save($completion);
            throw new RuntimeException('Forced E009 concurrency rollback after Completion persistence.');
        }
    };
    $forcedConcurrencyRollback = false;
    try {
        (new CompleteRepresentativeAcknowledgements(
            $concurrencyRequirementsA,
            $failingConcurrencyCompletions,
            new PdoTransactionRunner($managerA),
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(
            $representativeId,
            $rollbackPeriodId,
            [$rollbackRequirementId->value()],
            new DateTimeImmutable('2037-04-03 15:11:12+00:00'),
        ));
    } catch (RuntimeException $exception) {
        $forcedConcurrencyRollback = $exception->getMessage()
            === 'Forced E009 concurrency rollback after Completion persistence.';
    }
    $rollbackCompletionCount = $connectionA->prepare(
        'SELECT COUNT(*) FROM representative_acknowledgement_completions '
        . 'WHERE representative_id = :representativeId AND academic_period_id = :academicPeriodId'
    );
    $rollbackCompletionCount->execute([
        ':representativeId' => $representativeId,
        ':academicPeriodId' => $rollbackPeriodId,
    ]);
    $rollbackChildCount = $connectionA->prepare(
        'SELECT COUNT(*) FROM representative_acknowledgements WHERE acknowledgement_requirement_id = :id'
    );
    $rollbackChildCount->execute([':id' => $rollbackRequirementId->value()]);
    $lockReleasedAfterRollback = (new PdoTransactionRunner($managerB))->run(
        static fn () => $concurrencyRequirementsB->lockForPostUseUpdate($rollbackRequirementId)
    );
    $rollbackRequirementReloaded = $concurrencyRequirementsA->findById($rollbackRequirementId);
    assertIntegration(
        $forcedConcurrencyRollback
        && (int) $rollbackCompletionCount->fetchColumn() === 0
        && (int) $rollbackChildCount->fetchColumn() === 0
        && $rollbackRequirementReloaded?->title()->value() === 'Rollback protected title'
        && $lockReleasedAfterRollback?->id()?->equals($rollbackRequirementId) === true,
        'Concurrency rollback left partial Completion, child, Requirement state or retained lock.'
    );

    $creationFirstPeriodId = $concurrencyPeriodIds['E009_CONC_CREATE_SCOPE'];
    $creationFirstOriginal = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $creationFirstPeriodId,
        'Creation-first original Requirement',
        'CREATE-SCOPE-ORIGINAL',
    );
    $creationFirstOriginalId = $creationFirstOriginal->id();
    assertIntegration($creationFirstOriginalId !== null, 'Creation-first original Requirement has no identity.');
    $connectionA->beginTransaction();
    $concurrencyRequirementsA->lockConfigurationScope(
        new AcknowledgementAcademicPeriodId($creationFirstPeriodId)
    );
    $creationFirstAdded = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $creationFirstPeriodId,
        'Creation-first added Requirement',
        'CREATE-SCOPE-ADDED',
    );
    $creationFirstAddedId = $creationFirstAdded->id();
    assertIntegration($creationFirstAddedId !== null, 'Creation-first added Requirement has no identity.');
    $creationFirstCompletionBlocked = false;
    try {
        (new CompleteRepresentativeAcknowledgements(
            $concurrencyRequirementsB,
            $concurrencyCompletionsB,
            new PdoTransactionRunner($managerB),
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(
            $representativeId,
            $creationFirstPeriodId,
            [$creationFirstOriginalId->value()],
            new DateTimeImmutable('2037-05-03 15:11:12+00:00'),
        ));
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Creation-first Completion failed for a reason other than AcademicPeriod scope contention.',
                previous: $exception,
            );
        }
        $creationFirstCompletionBlocked = true;
    }
    assertIntegration(
        $creationFirstCompletionBlocked && !$connectionB->inTransaction(),
        'Completion crossed the AcademicPeriod scope while Requirement creation retained its lock.'
    );
    $connectionA->commit();
    $creationFirstStaleRejected = false;
    try {
        (new CompleteRepresentativeAcknowledgements(
            $concurrencyRequirementsB,
            $concurrencyCompletionsB,
            new PdoTransactionRunner($managerB),
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(
            $representativeId,
            $creationFirstPeriodId,
            [$creationFirstOriginalId->value()],
            new DateTimeImmutable('2037-05-03 15:11:12+00:00'),
        ));
    } catch (InvalidAcknowledgementConfirmation) {
        $creationFirstStaleRejected = true;
    }
    $creationFirstBeforeRetry = $concurrencyCompletionsA->findByRepresentativeAndAcademicPeriod(
        new AcknowledgementRepresentativeId($representativeId),
        new AcknowledgementAcademicPeriodId($creationFirstPeriodId),
    );
    $creationFirstCompleted = (new CompleteRepresentativeAcknowledgements(
        $concurrencyRequirementsB,
        $concurrencyCompletionsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CompleteRepresentativeAcknowledgementsInput(
        $representativeId,
        $creationFirstPeriodId,
        [$creationFirstOriginalId->value(), $creationFirstAddedId->value()],
        new DateTimeImmutable('2037-05-03 15:11:12+00:00'),
    ));
    $creationFirstExpectedIds = [$creationFirstOriginalId->value(), $creationFirstAddedId->value()];
    sort($creationFirstExpectedIds, SORT_NUMERIC);
    assertIntegration(
        $creationFirstStaleRejected
        && $creationFirstBeforeRetry === null
        && $creationFirstCompleted->acknowledgedRequirementIds === $creationFirstExpectedIds,
        'Creation-first serialization did not reject the stale set and persist the complete post-commit set.'
    );

    $completionAgainstCreationPeriodId = $concurrencyPeriodIds['E009_CONC_COMPLETE_SCOPE'];
    $completionAgainstCreationRequirement = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $completionAgainstCreationPeriodId,
        'Completion-first original Requirement',
        'COMPLETE-SCOPE-ORIGINAL',
    );
    $completionAgainstCreationRequirementId = $completionAgainstCreationRequirement->id();
    assertIntegration(
        $completionAgainstCreationRequirementId !== null,
        'Completion-first against creation Requirement has no identity.'
    );
    $connectionB->beginTransaction();
    $completionAgainstCreationPeriod = new AcknowledgementAcademicPeriodId($completionAgainstCreationPeriodId);
    $concurrencyRequirementsB->lockConfigurationScope($completionAgainstCreationPeriod);
    $completionAgainstCreationLocked = $concurrencyRequirementsB->lockForCompletion(
        $completionAgainstCreationPeriod
    );
    $completionAgainstCreationPersisted = $concurrencyCompletionsB->save(
        RepresentativeAcknowledgementCompletion::complete(
            new AcknowledgementRepresentativeId($representativeId),
            $completionAgainstCreationPeriod,
            new DateTimeImmutable('2037-06-03 15:11:12+00:00'),
            $completionAgainstCreationLocked,
        )
    );
    $completionFirstCreationBlocked = false;
    try {
        (new CreateAcknowledgementRequirement(
            $concurrencyRequirementsA,
            new PdoTransactionRunner($managerA),
        ))->handle(new CreateAcknowledgementRequirementInput(
            $completionAgainstCreationPeriodId,
            'Completion-first delayed Requirement',
            'https://example.test/e009/concurrency/complete-scope-added',
            'COMPLETE-SCOPE-ADDED',
            'ACTIVE',
        ));
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Completion-first Requirement creation failed outside AcademicPeriod scope contention.',
                previous: $exception,
            );
        }
        $completionFirstCreationBlocked = true;
    }
    assertIntegration(
        $completionFirstCreationBlocked && !$connectionA->inTransaction(),
        'Requirement creation crossed the AcademicPeriod scope while Completion retained its lock.'
    );
    $connectionB->commit();
    $completionFirstCreatedAfterCommit = (new CreateAcknowledgementRequirement(
        $concurrencyRequirementsA,
        new PdoTransactionRunner($managerA),
    ))->handle(new CreateAcknowledgementRequirementInput(
        $completionAgainstCreationPeriodId,
        'Completion-first delayed Requirement',
        'https://example.test/e009/concurrency/complete-scope-added',
        'COMPLETE-SCOPE-ADDED',
        'ACTIVE',
    ));
    $completionAgainstCreationReloaded = $concurrencyCompletionsA->findByRepresentativeAndAcademicPeriod(
        new AcknowledgementRepresentativeId($representativeId),
        $completionAgainstCreationPeriod,
    );
    $completionAgainstCreationChildIds = array_map(
        static fn ($acknowledgement): int => $acknowledgement->acknowledgementRequirementId()->value(),
        $completionAgainstCreationReloaded?->acknowledgements() ?? [],
    );
    assertIntegration(
        ($completionAgainstCreationPersisted->id()?->value() ?? 0) > 0
        && $completionFirstCreatedAfterCommit->id > 0
        && $completionAgainstCreationChildIds === [$completionAgainstCreationRequirementId->value()]
        && (new CheckInstitutionalAcknowledgementSatisfaction(
            $concurrencyRequirementsA,
            $concurrencyCompletionsA,
        ))->isSatisfied($representativeId, $completionAgainstCreationPeriodId),
        'Completion-first serialization did not preserve the historical set before later Requirement creation.'
    );

    $zeroRequirementPeriodId = $concurrencyPeriodIds['E009_CONC_ZERO_SCOPE'];
    $connectionA->beginTransaction();
    $zeroRequirementPeriod = new AcknowledgementAcademicPeriodId($zeroRequirementPeriodId);
    $concurrencyRequirementsA->lockConfigurationScope($zeroRequirementPeriod);
    $zeroLockedRequirements = $concurrencyRequirementsA->lockForCompletion($zeroRequirementPeriod);
    assertIntegration($zeroLockedRequirements === [], 'Zero-Requirement Completion observed an unexpected Requirement.');
    $zeroCreationBlocked = false;
    try {
        (new CreateAcknowledgementRequirement(
            $concurrencyRequirementsB,
            new PdoTransactionRunner($managerB),
        ))->handle(new CreateAcknowledgementRequirementInput(
            $zeroRequirementPeriodId,
            'Post-zero Requirement',
            'https://example.test/e009/concurrency/zero-added',
            'ZERO-SCOPE-ADDED',
            'ACTIVE',
        ));
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Zero-Requirement creation failed outside AcademicPeriod scope contention.',
                previous: $exception,
            );
        }
        $zeroCreationBlocked = true;
    }
    assertIntegration(
        $zeroCreationBlocked && !$connectionB->inTransaction(),
        'Requirement creation crossed a zero-Requirement Completion configuration scope.'
    );
    $connectionA->commit();
    $zeroCreatedAfterCommit = (new CreateAcknowledgementRequirement(
        $concurrencyRequirementsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CreateAcknowledgementRequirementInput(
        $zeroRequirementPeriodId,
        'Post-zero Requirement',
        'https://example.test/e009/concurrency/zero-added',
        'ZERO-SCOPE-ADDED',
        'ACTIVE',
    ));
    $zeroCompletion = $concurrencyCompletionsA->findByRepresentativeAndAcademicPeriod(
        new AcknowledgementRepresentativeId($representativeId),
        $zeroRequirementPeriod,
    );
    assertIntegration(
        $zeroCreatedAfterCommit->id > 0
        && $zeroCompletion === null
        && !(new CheckInstitutionalAcknowledgementSatisfaction(
            $concurrencyRequirementsA,
            $concurrencyCompletionsA,
        ))->isSatisfied($representativeId, $zeroRequirementPeriodId),
        'Zero-Requirement serialization persisted a Completion or remained satisfied after active creation.'
    );

    $isolatedScopeAPeriodId = $concurrencyPeriodIds['E009_CONC_SCOPE_A'];
    $isolatedScopeBPeriodId = $concurrencyPeriodIds['E009_CONC_SCOPE_B'];
    $connectionA->beginTransaction();
    $concurrencyRequirementsA->lockConfigurationScope(
        new AcknowledgementAcademicPeriodId($isolatedScopeAPeriodId)
    );
    $isolatedScopeBCreated = (new CreateAcknowledgementRequirement(
        $concurrencyRequirementsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CreateAcknowledgementRequirementInput(
        $isolatedScopeBPeriodId,
        'Isolated scope B Requirement',
        'https://example.test/e009/concurrency/scope-b',
        'SCOPE-B',
        'ACTIVE',
    ));
    $isolatedScopeBCompletion = (new CompleteRepresentativeAcknowledgements(
        $concurrencyRequirementsB,
        $concurrencyCompletionsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CompleteRepresentativeAcknowledgementsInput(
        $representativeId,
        $isolatedScopeBPeriodId,
        [$isolatedScopeBCreated->id],
        new DateTimeImmutable('2037-07-03 15:11:12+00:00'),
    ));
    $connectionA->rollBack();
    assertIntegration(
        $isolatedScopeBCreated->id > 0
        && ($isolatedScopeBCompletion->completionId ?? 0) > 0
        && $isolatedScopeBCompletion->acknowledgedRequirementIds === [$isolatedScopeBCreated->id],
        'AcademicPeriod A scope lock blocked independent Requirement creation or Completion in period B.'
    );

    $creationRollbackPeriodId = $concurrencyPeriodIds['E009_CONC_CREATE_ROLLBACK'];
    $connectionA->beginTransaction();
    $concurrencyRequirementsA->lockConfigurationScope(
        new AcknowledgementAcademicPeriodId($creationRollbackPeriodId)
    );
    $rolledBackCreation = $newConcurrencyRequirement(
        $concurrencyRequirementsA,
        $creationRollbackPeriodId,
        'Rolled-back Requirement creation',
        'CREATE-ROLLBACK',
    );
    $rolledBackCreationId = $rolledBackCreation->id();
    assertIntegration($rolledBackCreationId !== null, 'Rolled-back Requirement creation has no identity.');
    $connectionA->rollBack();
    $rolledBackCreationReloaded = $concurrencyRequirementsB->findById($rolledBackCreationId);
    $creationAfterRollback = (new CreateAcknowledgementRequirement(
        $concurrencyRequirementsB,
        new PdoTransactionRunner($managerB),
    ))->handle(new CreateAcknowledgementRequirementInput(
        $creationRollbackPeriodId,
        'Requirement after rollback',
        'https://example.test/e009/concurrency/after-rollback',
        'CREATE-AFTER-ROLLBACK',
        'ACTIVE',
    ));
    assertIntegration(
        $rolledBackCreationReloaded === null && $creationAfterRollback->id > 0,
        'Requirement creation rollback retained data or failed to release the AcademicPeriod scope lock.'
    );

    echo "PASS MySQL E009 Requirement post-use Update-first serialization and post-commit Completion\n";
    echo "PASS MySQL E009 Requirement post-use Completion-first protection and mutable URL status\n";
    echo "PASS MySQL E009 Requirement deterministic multi-lock order rollback and lock release\n";
    echo "PASS MySQL E009 Requirement creation-first serialization rejects stale Completion sets\n";
    echo "PASS MySQL E009 Completion-first serialization preserves history before later Requirement creation\n";
    echo "PASS MySQL E009 zero-Requirement scope cross-period isolation and creation rollback release\n";

    $knownLockInstant = new DateTimeImmutable('2026-07-31 07:34:56-05:00');
    $repositoryA = new PdoUserRepository($managerA);
    $utcUser = $repositoryA->findByLoginIdentifier(new LoginIdentifier('admin'));
    assertIntegration($utcUser !== null, 'UTC repository probe could not load User.');
    $utcUser->recordFailedLogin($knownLockInstant, 5);
    $repositoryA->save($utcUser);
    $reloadedUtcUser = $repositoryA->findByLoginIdentifier(new LoginIdentifier('admin'));
    assertIntegration(
        $reloadedUtcUser?->lockedAt()?->getTimestamp() === $knownLockInstant->getTimestamp(),
        'Repository locked_at did not preserve the expected UTC instant.'
    );

    $unrelatedSqlError = new PDOException("Table 'missing' doesn't exist");
    $unrelatedSqlError->errorInfo = ['42S02', 1146, "Table 'missing' doesn't exist"];
    assertIntegration(
        !isExpectedMariaDbLockException($unrelatedSqlError),
        'An unrelated SQL exception was misclassified as a concurrency lock.'
    );
    $connectionA->beginTransaction();
    (new PdoUserRepository($managerA))->findByLoginIdentifierForUpdate(
        new LoginIdentifier('admin')
    );
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $connectionB->beginTransaction();
    $lockBlocked = false;
    try {
        (new PdoUserRepository($managerB))->findByLoginIdentifierForUpdate(
            new LoginIdentifier('admin')
        );
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Concurrent User load failed for a reason other than a MariaDB lock conflict.',
                previous: $exception
            );
        }
        $lockBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }
    assertIntegration($lockBlocked, 'Concurrent User load was not protected by a row lock.');

    $identity->exec('UPDATE academic_periods SET status_id = ' . $inactiveGeneralStatusId);
    $insertOperationalPeriod = $identity->prepare(
        'INSERT INTO academic_periods (code, name, starts_on, ends_on, status_id) '
        . 'VALUES (:code, :name, :startsOn, :endsOn, :statusId)'
    );
    $operationalPeriodIds = [];
    foreach ([
        ['E009_PHASE51_A', 'Phase 5.1 period A', '2035-09-01', '2036-06-30'],
        ['E009_PHASE51_B', 'Phase 5.1 period B', '2036-09-01', '2037-06-30'],
        ['E009_PHASE51_C', 'Phase 5.1 period C', '2037-09-01', '2038-06-30'],
    ] as [$code, $name, $startsOn, $endsOn]) {
        $insertOperationalPeriod->execute([
            ':code' => $code,
            ':name' => $name,
            ':startsOn' => $startsOn,
            ':endsOn' => $endsOn,
            ':statusId' => $inactiveGeneralStatusId,
        ]);
        $operationalPeriodIds[] = (int) $identity->lastInsertId();
    }
    [$operationalPeriodAId, $operationalPeriodBId, $operationalPeriodCId] = $operationalPeriodIds;
    assertIntegration(
        $operationalPeriodAId > 0
        && $operationalPeriodBId > 0
        && $operationalPeriodCId > 0
        && count(array_unique($operationalPeriodIds)) === 3,
        'MariaDB did not generate distinct positive AcademicPeriod lifecycle identities.'
    );

    $academicPeriodsA = new PdoAcademicPeriodRepository($managerA);
    $academicPeriodsB = new PdoAcademicPeriodRepository($managerB);
    $activeAcademicPeriod = new GetActiveAcademicPeriod($academicPeriodsA);
    $activateAcademicPeriodA = new ActivateAcademicPeriod(
        $academicPeriodsA,
        new PdoTransactionRunner($managerA),
    );
    $deactivateAcademicPeriodA = new DeactivateAcademicPeriod(
        $academicPeriodsA,
        new PdoTransactionRunner($managerA),
    );

    assertIntegration(
        $activeAcademicPeriod->handle() === null,
        'AcademicPeriod was inferred from dates while every period was INACTIVE.'
    );
    $activatedA = $activateAcademicPeriodA->handle($operationalPeriodAId);
    assertIntegration(
        $activatedA->id === $operationalPeriodAId
        && $activatedA->code === 'E009_PHASE51_A'
        && $activatedA->name === 'Phase 5.1 period A'
        && $activatedA->startsOn === '2035-09-01'
        && $activatedA->endsOn === '2036-06-30'
        && $activatedA->status === 'ACTIVE'
        && $activeAcademicPeriod->handle()?->id === $operationalPeriodAId,
        'MariaDB AcademicPeriod activation did not return safe exact persisted output.'
    );

    $activatedB = $activateAcademicPeriodA->handle($operationalPeriodBId);
    $activeCount = (int) $identity->query(
        'SELECT COUNT(*) FROM academic_periods ap '
        . 'INNER JOIN statuses s ON s.id = ap.status_id '
        . 'INNER JOIN status_types st ON st.id = s.status_type_id '
        . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
    )->fetchColumn();
    assertIntegration(
        $activatedB->id === $operationalPeriodBId
        && $activeAcademicPeriod->handle()?->id === $operationalPeriodBId
        && $academicPeriodsA->findById(new CoreAcademicPeriodId($operationalPeriodAId))?->status()->value === 'INACTIVE'
        && $activeCount === 1,
        'Activating AcademicPeriod B did not atomically deactivate A.'
    );

    $deactivatedB = $deactivateAcademicPeriodA->handle($operationalPeriodBId);
    assertIntegration(
        $deactivatedB->status === 'INACTIVE' && $activeAcademicPeriod->handle() === null,
        'Deactivating the operational AcademicPeriod did not permit zero ACTIVE periods.'
    );
    $activateAcademicPeriodA->handle($operationalPeriodAId);

    $identity->exec(
        'UPDATE academic_periods SET status_id = ' . $generalStatusId
        . ' WHERE id = ' . $operationalPeriodBId
    );
    $multipleActiveRejected = false;
    try {
        $academicPeriodsA->findActive();
    } catch (AcademicPeriodOperationalStateConflict) {
        $multipleActiveRejected = true;
    }
    assertIntegration(
        $multipleActiveRejected,
        'MariaDB AcademicPeriod persistence did not fail closed for multiple ACTIVE periods.'
    );
    $identity->exec(
        'UPDATE academic_periods SET status_id = ' . $inactiveGeneralStatusId
        . ' WHERE id = ' . $operationalPeriodBId
    );

    $identity->exec(
        'UPDATE academic_periods SET status_id = ' . $disabledUserStatusId
        . ' WHERE id = ' . $operationalPeriodCId
    );
    $wrongStatusTypeRejected = false;
    $wrongStatusTypeRejectedByActiveResolution = false;
    try {
        $academicPeriodsA->findById(new CoreAcademicPeriodId($operationalPeriodCId));
    } catch (RuntimeException) {
        $wrongStatusTypeRejected = true;
    }
    try {
        $academicPeriodsA->findActive();
    } catch (RuntimeException) {
        $wrongStatusTypeRejectedByActiveResolution = true;
    }
    assertIntegration(
        $wrongStatusTypeRejected && $wrongStatusTypeRejectedByActiveResolution,
        'MariaDB AcademicPeriod persistence accepted a status outside GENERAL_STATUS.'
    );
    $identity->exec(
        'UPDATE academic_periods SET status_id = ' . $inactiveGeneralStatusId
        . ' WHERE id = ' . $operationalPeriodCId
    );

    $failingAcademicPeriods = new class($academicPeriodsA) implements AcademicPeriodRepository {
        private int $saveCount = 0;

        public function __construct(private readonly AcademicPeriodRepository $inner)
        {
        }

        public function findById(CoreAcademicPeriodId $id): ?AcademicPeriod
        {
            return $this->inner->findById($id);
        }

        public function findActive(): ?AcademicPeriod
        {
            return $this->inner->findActive();
        }

        public function save(AcademicPeriod $period): AcademicPeriod
        {
            $this->saveCount++;
            if ($this->saveCount === 2) {
                throw new RuntimeException('Forced AcademicPeriod activation failure.');
            }

            return $this->inner->save($period);
        }

        public function lockOperationalTransition(): void
        {
            $this->inner->lockOperationalTransition();
        }

        public function lockActiveContextForRead(): void
        {
            $this->inner->lockActiveContextForRead();
        }
    };
    $rollbackObserved = false;
    try {
        (new ActivateAcademicPeriod(
            $failingAcademicPeriods,
            new PdoTransactionRunner($managerA),
        ))->handle($operationalPeriodBId);
    } catch (RuntimeException $exception) {
        $rollbackObserved = $exception->getMessage() === 'Forced AcademicPeriod activation failure.';
    }
    assertIntegration(
        $rollbackObserved
        && $activeAcademicPeriod->handle()?->id === $operationalPeriodAId
        && $academicPeriodsA->findById(new CoreAcademicPeriodId($operationalPeriodBId))?->status()->value === 'INACTIVE',
        'Failed AcademicPeriod activation did not roll back the prior deactivation.'
    );

    $connectionA->beginTransaction();
    $academicPeriodsA->lockOperationalTransition();
    $connectionB->exec('SET innodb_lock_wait_timeout = 1');
    $competingActivationBlocked = false;
    try {
        (new ActivateAcademicPeriod(
            $academicPeriodsB,
            new PdoTransactionRunner($managerB),
        ))->handle($operationalPeriodBId);
    } catch (PDOException $exception) {
        if (!isExpectedMariaDbLockException($exception)) {
            throw new RuntimeException(
                'Competing AcademicPeriod activation failed for a reason other than lock contention.',
                previous: $exception,
            );
        }
        $competingActivationBlocked = true;
    } finally {
        if ($connectionB->inTransaction()) {
            $connectionB->rollBack();
        }
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }
    assertIntegration(
        $competingActivationBlocked
        && $activeAcademicPeriod->handle()?->id === $operationalPeriodAId,
        'Competing AcademicPeriod activation bypassed the stable GENERAL_STATUS/ACTIVE lock.'
    );

    $serializedB = (new ActivateAcademicPeriod(
        $academicPeriodsB,
        new PdoTransactionRunner($managerB),
    ))->handle($operationalPeriodBId);
    assertIntegration(
        $serializedB->id === $operationalPeriodBId
        && $activeAcademicPeriod->handle()?->id === $operationalPeriodBId
        && (int) $identity->query(
            'SELECT COUNT(*) FROM academic_periods ap '
            . 'INNER JOIN statuses s ON s.id = ap.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . "WHERE st.code = 'GENERAL_STATUS' AND s.code = 'ACTIVE'"
        )->fetchColumn() === 1,
        'Serialized competing AcademicPeriod activations did not finish with exactly one ACTIVE period.'
    );

    $physicalOptions = (new PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider($managerA))->all();
    $physicalOptionStatuses = [];
    foreach ($physicalOptions as $option) {
        if (in_array($option->id, $operationalPeriodIds, true)) {
            $physicalOptionStatuses[$option->id] = $option->status;
        }
    }
    assertIntegration(
        $physicalOptionStatuses[$operationalPeriodAId] === 'INACTIVE'
        && $physicalOptionStatuses[$operationalPeriodBId] === 'ACTIVE'
        && $physicalOptionStatuses[$operationalPeriodCId] === 'INACTIVE',
        'E009 administrator AcademicPeriod provider did not expose exact lifecycle states.'
    );

    if ($generatedStudentId === null
        || $secondStudentId === null
        || $generatedFamilyId === null
        || $generatedRepresentativeId === null
        || $generatedPersonId === null
        || $secondGeneratedPersonId === null
    ) {
        throw new RuntimeException('E010 Enrollment persistence requires prior generated Student and Family identities.');
    }
    runMariaDbEnrollmentPersistenceScenario(
        $managerA,
        $connectionA,
        $generatedStudentId->value(),
        $secondStudentId->value(),
        $generatedFamilyId->value(),
        $generatedRepresentativeId->value(),
        $persistencePeriodId,
        $persistenceOtherPeriodId,
        $persistenceRollbackPeriodId,
        $generalStatusId,
    );
    runMariaDbEnrollmentApplicationConcurrencyScenario(
        $managerA,
        $managerB,
        $connectionA,
        $connectionB,
        $generatedStudentId->value(),
        $secondStudentId->value(),
        $generatedFamilyId->value(),
        $persistencePeriodId,
        $persistenceOtherPeriodId,
        $persistenceRollbackPeriodId,
        $generalStatusId,
    );
    runMariaDbEnrollmentActiveFamilyCaptureScenario(
        $managerA,
        $managerB,
        $connectionA,
        $connectionB,
        $generatedStudentId->value(),
        $secondStudentId->value(),
        $generatedRepresentativeId->value(),
    );
    runMariaDbRepresentativeEnrollmentPortalConcurrencyScenario(
        $managerA,
        $managerB,
        $connectionA,
        $connectionB,
        $generatedPersonId->value(),
        $secondGeneratedPersonId->value(),
        $generatedRepresentativeId->value(),
        $generatedStudentId->value(),
        $operationalPeriodBId,
        $operationalPeriodAId,
    );
    $submissionFamily = $familyRepository->findActiveByStudentId(
        new FamilyStudentReference($generatedStudentId->value())
    );
    $submissionFamilyId = $submissionFamily?->id()?->value() ?? 0;
    assertIntegration(
        $submissionFamilyId > 0,
        'E012 Submission could not resolve the Student current active Family after E010 regression.'
    );
    $submissionOtherFamilyId = $submissionFamilyId === $generatedFamilyId->value()
        ? $phase4FamilyAId->value()
        : $generatedFamilyId->value();
    runMariaDbEnrollmentSubmissionApplicationScenario(
        $managerA,
        $managerB,
        $connectionA,
        $connectionB,
        $authenticationSession,
        $generatedRepresentativeUserId,
        $representativeUserPersonId->value(),
        $representativeUserRoleId->value(),
        $generatedStudentId->value(),
        $submissionFamilyId,
        $submissionOtherFamilyId,
        $generatedRelationshipTypeId,
    );
    runMariaDbEnrollmentAdministrativeLifecycleScenario(
        $managerA,
        $managerB,
        $connectionA,
        $connectionB,
        $generatedStudentId->value(),
        $secondStudentId->value(),
        $submissionFamilyId,
    );
    runMariaDbSubmittedEnrollmentQueryScenario(
        $managerA,
        $connectionA,
        $generatedStudentId->value(),
        $secondStudentId->value(),
        $submissionFamilyId,
    );
    runMariaDbEnrollmentReportingScenario($managerA, $connectionA);
    runMariaDbBulkImportApplicationScenario($managerA, $connectionA);

    echo 'MariaDB version: ' . $mariaDbVersion . "\n";
    echo 'Physical inventory: ' . count($actualTables) . ' tables including migrations metadata; '
        . $physicalForeignKeyCount . " foreign keys\n";
    echo "PASS MySQL clean migration creates the exact 31-table domain baseline plus migrations metadata\n";
    echo "PASS MySQL migration 010 removes legacy snapshot tables and supports rollback plus reapply\n";
    echo "PASS MySQL migration 011 FamilyCode fresh schema legacy backfill range guard and physical uniqueness\n";
    echo "PASS MySQL Institutional Acknowledgements AUTO_INCREMENT UTC constraints ownership and rollback\n";
    echo "PASS MySQL Institutional Acknowledgements repository roundtrip AUTO_INCREMENT UTC transactions and history\n";
    echo "PASS MySQL Institutional Acknowledgements administrator AcademicPeriod provider context hardening and Application persistence\n";
    echo "PASS MySQL approved status seed baseline\n";
    echo "PASS MySQL AdminSeeder preserves existing credentials and status\n";
    echo "PASS MySQL UTC repository locked_at persistence\n";
    echo "PASS MySQL concurrent User row locking with specific MariaDB error classification\n";
    echo "PASS MySQL Active AcademicPeriod lifecycle atomic transition fail-closed rollback and serialization\n";
    echo "PASS MySQL database-generated Person identities and complete aggregate reconstruction\n";
    echo "PASS MySQL Person normalized identification lookup and uniqueness\n";
    echo "PASS MySQL Person update, nullable fields and GENERAL_STATUS mapping\n";
    echo "PASS MySQL Representative AUTO_INCREMENT lookup and EmploymentInformation persistence\n";
    echo "PASS MySQL Representative update uniqueness and exact GENERAL_STATUS mapping\n";
    echo "PASS MySQL Student AUTO_INCREMENT lookups and AdmissionDate reconstruction\n";
    echo "PASS MySQL Student administrative update uniqueness collation and exact GENERAL_STATUS mapping\n";
    echo "PASS MySQL Family atomic AUTO_INCREMENT creation and complete Aggregate reconstruction\n";
    echo "PASS MySQL Family Representative and Student active lookups and historical membership\n";
    echo "PASS MySQL Family physical uniqueness UTC status mapping and transactional rollback\n";
    echo "PASS MySQL FamilyCode exact lookup FOR UPDATE Aggregate roundtrip immutability and rollback\n";
    echo "PASS MySQL Family Resources complete roundtrip AUTO_INCREMENT UTC constraints and rollback\n";
    echo "PASS MySQL Family delivery active catalogs and Application persistence\n";
    echo "PASS MySQL E015 mandatory Representative Person role User Family atomic commit hashing and status\n";
    echo "PASS MySQL E015 Representative password duplicate-login and post-User Family rollback leave no orphans\n";
    echo "PASS MySQL E015 Phase 6 Preview Apply AUTO_INCREMENT UTC idempotency conflict rollback and password retention\n";
    echo "PASS MySQL E015 Phase 7 HTTP session template Preview exact reupload Apply PRG result CSV conflict concurrency and cleanup\n";
    echo "PASS MySQL Representative personal email invariant and work-email non-substitution\n";
    echo "PASS MySQL composite Student Person role membership atomic commit and rollback\n";
    echo "PASS MySQL Representative User email-gated AUTO_INCREMENT provisioning lookup hashing and physical uniqueness\n";
    echo "PASS MySQL Representative administrative password change preserves authentication state\n";
    echo "PASS MySQL Representative email document login synchronization conflict rollback and authentication\n";
    echo "PASS MySQL Enrollment complete roundtrip AUTO_INCREMENT UTC ENROLLMENT_STATUS and immutable ownership\n";
    echo "PASS MySQL Enrollment submission lifecycle persists timestamps without duplicated snapshot state\n";
    echo "PASS MySQL Enrollment completion cancellation uniqueness caller transaction and rollback\n";
    echo "PASS MySQL Enrollment failed insertion preserves transaction ownership and leaves no partial root\n";
    echo "PASS MySQL Enrollment Application same-root serialization preserves Billing and Medical updates\n";
    echo "PASS MySQL Enrollment Application cross-root isolation and rollback lock release\n";
    echo "PASS MySQL Enrollment Application concurrent initialization physical UNIQUE and no partial state\n";
    echo "PASS MySQL Enrollment active Family capture Start-first and current-Family serialization\n";
    echo "PASS MySQL Enrollment stale Family rejection after membership-change-first\n";
    echo "PASS MySQL Enrollment FamilyStudent lock rollback release and cross-Student isolation\n";
    echo "PASS MySQL E011 Person and Representative same-row serialization cross-root isolation and rollback release\n";
    echo "PASS MySQL E011 FamilyRepresentative and FamilyStudent portal-first revocation-first serialization\n";
    echo "PASS MySQL E011 ActivePeriod shared portal locks lifecycle exclusion and stale-page rejection\n";
    echo "PASS MySQL E012 Submission first transition Resubmission stable validation and persisted verification\n";
    echo "PASS MySQL E012 zero-Requirement Submission Requirement-first Completion and shared configuration locking\n";
    echo "PASS MySQL E012 annual autosave-first post-Submission annual rejection and live-data mutability\n";
    echo "PASS MySQL E012 Family root same-Family serialization cross-Family isolation rollback and lock release\n";
    echo "PASS MySQL E012 stale ActivePeriod rejection with accumulated E009 E010 E011 concurrency regression\n";
    echo "PASS MySQL E012 Administrative lifecycle roundtrip UTC annual preservation and exact review\n";
    echo "PASS MySQL E012 Administrative same-root concurrency matrix and cross-root isolation\n";
    echo "PASS MySQL E012 Administrative rollback no-partial-state and root-lock release\n";
    echo "PASS MySQL E012 Administrative Delivery Submitted query state order period context and side-effect freedom\n";
    echo "PASS MySQL E013 reporting AcademicPeriod options default and explicit historical selection\n";
    echo "PASS MySQL E013 Enrollment Summary status placement and inactive Student counting\n";
    echo "PASS MySQL E013 Student Directory Billing Medical selected-period and current-live projections\n";
    echo "PASS MySQL E013 reporting deterministic one-row ACTIVE population read-only queries and plans\n";
    echo "PASS MySQL Academic Core Grade Section references and next ACTIVE Grade ordering\n";
    echo "PASS MySQL partial disposable database creation cleanup\n";
} finally {
    $cleanupFailures = dropDisposableDatabases($server, $createdDatabases);
    if ($cleanupFailures !== []) {
        throw new RuntimeException(
            'Disposable database cleanup failed after all drops were attempted: ' . implode('; ', $cleanupFailures)
        );
    }
}
