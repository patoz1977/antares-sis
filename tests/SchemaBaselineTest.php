<?php

declare(strict_types=1);

namespace Tests;

use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\MigrationRunner;
use Core\Foundation\Application;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\TestRunner;

function registerSchemaBaselineTests(TestRunner $runner): void
{
    $runner->add('migration baseline declares the exact 35-table domain inventory', function (): void {
        $expected = [
            'academic_periods', 'acknowledgement_requirements', 'authorized_pickup_assignments', 'cantons',
            'document_types', 'education_levels', 'emergency_contact_assignments',
            'enrollment_submission_snapshots', 'enrollments',
            'families', 'family_addresses', 'family_authorized_pickups', 'family_emergency_contacts',
            'family_representatives', 'family_students', 'grades', 'marital_statuses', 'parishes', 'persons', 'provinces',
            'relationship_types', 'representative_address_assignments', 'representatives', 'sections',
            'representative_acknowledgement_completions', 'representative_acknowledgements',
            'sexes', 'snapshot_addresses', 'snapshot_authorized_pickups', 'snapshot_emergency_contacts',
            'statuses', 'status_types', 'student_address_assignments', 'students', 'users',
        ];

        $actual = [];
        foreach (baselineMigrationSources() as $source) {
            array_push($actual, ...baselineDeclaredTables($source));
        }

        sort($actual);
        sort($expected);
        assertBaselineSame($expected, $actual, 'The functional migration inventory differs from DATABASE_DESIGN.');
        assertBaselineSame(count($actual), count(array_unique($actual)), 'A domain table is declared more than once.');
    });

    $runner->add('migration runner loads the clean ordered sequence', function (): void {
        $config = new DatabaseConfig([
            'driver' => 'mysql', 'host' => 'not-used', 'port' => 3306, 'database' => 'not-used',
            'username' => 'not-used', 'password' => 'not-used', 'charset' => 'utf8mb4',
        ]);
        $runner = new MigrationRunner(new ConnectionManager(new ConnectionFactory(), $config));
        $loader = new ReflectionMethod(MigrationRunner::class, 'loadMigrations');
        $loader->setAccessible(true);
        $migrations = $loader->invoke($runner);
        $versions = array_map(static fn (object $migration): string => $migration->version(), $migrations);

        assertBaselineSame([
            '001_create_migrations_table', '002_create_status_schema', '003_create_reference_catalogs',
            '004_create_academic_core', '005_create_identity_and_roles', '006_create_family_management',
            '007_create_institutional_documents', '008_create_enrollment', '009_create_submission_snapshots',
        ], $versions, 'Migration runner did not load the expected ordered sequence.');
        assertBaselineSame(
            [],
            glob(dirname(__DIR__) . '/database/migrations/010_*.php') ?: [],
            'Migration 010 is forbidden while ADR-0018 authorizes the corrected clean baseline.'
        );
    });

    $runner->add('MariaDB connections use the approved schema character set and collation', function (): void {
        $source = file_get_contents(dirname(__DIR__) . '/core/Database/ConnectionFactory.php');
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read ConnectionFactory source.');
        }

        assertBaselineSame(
            true,
            str_contains($source, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'),
            'ConnectionFactory must align MariaDB sessions with the approved schema collation.'
        );
    });

    $runner->add('Person and User columns match the approved baseline', function (): void {
        $source = file_get_contents(dirname(__DIR__) . '/database/migrations/005_create_identity_and_roles.php');
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read the identity migration.');
        }

        assertBaselineSame([
            'id', 'first_name', 'middle_name', 'first_surname', 'second_surname',
            'document_type_id', 'document_number', 'identification_key', 'birth_date', 'sex_id',
            'marital_status_id', 'education_level_id', 'email', 'mobile_phone', 'landline_phone',
            'status_id', 'created_at', 'updated_at',
        ], baselineTableColumns($source, 'persons'), 'Person columns differ from the approved baseline.');

        assertBaselineSame([
            'id', 'person_id', 'login_identifier', 'normalized_login_identifier', 'password_hash',
            'status_id', 'last_access_at', 'failed_login_attempts', 'locked_at', 'created_at', 'updated_at',
        ], baselineTableColumns($source, 'users'), 'User columns differ from the approved baseline.');
    });

    $runner->add('Family address and submitted snapshot use the simplified address baseline', function (): void {
        $migrationSource = implode("\n", baselineMigrationSources());
        assertBaselineSame([
            'id', 'family_id', 'label', 'main_street', 'street_number', 'secondary_street',
            'sector', 'reference', 'latitude', 'longitude', 'status_id', 'created_at', 'updated_at',
        ], baselineTableColumns($migrationSource, 'family_addresses'), 'FamilyAddress columns differ from ADR-0021.');
        assertBaselineSame([
            'id', 'enrollment_submission_snapshot_id', 'label', 'main_street', 'street_number',
            'secondary_street', 'sector', 'reference', 'latitude', 'longitude', 'created_at',
        ], baselineTableColumns($migrationSource, 'snapshot_addresses'), 'SubmittedAddressSnapshot columns differ from ADR-0021.');

        $pattern = '/CREATE TABLE `family_addresses` \((.*?)\n\s*\) ENGINE=/s';
        if (preg_match($pattern, $migrationSource, $match) !== 1) {
            throw new RuntimeException('Unable to inspect the FamilyAddress migration declaration.');
        }
        $familyAddressDefinition = $match[1];

        preg_match_all('/(?:UNIQUE )?KEY `([^`]+)`/', $familyAddressDefinition, $keys);
        assertBaselineSame([
            'uq_family_addresses_id_family',
            'idx_family_addresses_family_status',
        ], $keys[1], 'FamilyAddress keys differ from the simplified baseline.');

        preg_match_all('/CONSTRAINT `([^`]+)`/', $familyAddressDefinition, $constraints);
        assertBaselineSame([
            'chk_family_addresses_coordinates_pair',
            'chk_family_addresses_latitude',
            'chk_family_addresses_longitude',
            'fk_family_addresses_family',
            'fk_family_addresses_status',
        ], $constraints[1], 'FamilyAddress constraints differ from the simplified baseline.');

        foreach (['province_id', 'canton_id', 'parish_id'] as $retiredColumn) {
            assertBaselineSame(
                false,
                str_contains($familyAddressDefinition, $retiredColumn),
                sprintf('FamilyAddress retains retired geographic schema: %s.', $retiredColumn)
            );
        }
    });

    $runner->add('Institutional Acknowledgements replace versioned Enrollment acceptance schema', function (): void {
        $migrationSource = implode("\n", baselineMigrationSources());

        assertBaselineSame([
            'id', 'academic_period_id', 'title', 'url', 'official_reference',
            'status_id', 'created_at', 'updated_at',
        ], baselineTableColumns($migrationSource, 'acknowledgement_requirements'), 'AcknowledgementRequirement columns differ from ADR-0022.');
        assertBaselineSame([
            'id', 'representative_id', 'academic_period_id', 'completed_at',
        ], baselineTableColumns($migrationSource, 'representative_acknowledgement_completions'), 'Completion columns differ from ADR-0022.');
        assertBaselineSame([
            'id', 'representative_acknowledgement_completion_id',
            'acknowledgement_requirement_id', 'academic_period_id',
        ], baselineTableColumns($migrationSource, 'representative_acknowledgements'), 'Acknowledgement columns differ from ADR-0022.');

        foreach ([
            'institutional_documents',
            'institutional_document_versions',
            'document_requirements',
            'enrollment_document_acceptances',
        ] as $retiredTable) {
            assertBaselineSame(
                false,
                in_array($retiredTable, baselineDeclaredTables($migrationSource), true),
                sprintf('Retired document/version acceptance table remains: %s.', $retiredTable)
            );
        }

        foreach (['file_reference', 'published_at', 'is_required', 'accepted_at'] as $retiredColumn) {
            assertBaselineSame(
                false,
                str_contains($migrationSource, '`' . $retiredColumn . '`'),
                sprintf('Retired document/version acceptance column remains: %s.', $retiredColumn)
            );
        }

        $acknowledgementDefinition = baselineTableDefinition($migrationSource, 'representative_acknowledgements');
        assertBaselineSame(false, str_contains($acknowledgementDefinition, '`enrollment_id`'), 'Acknowledgements must not depend on Enrollment.');
        assertBaselineSame(false, str_contains($acknowledgementDefinition, '`representative_id`'), 'RepresentativeId is derived from Completion and must not be duplicated.');

        foreach ([
            'uq_ack_requirements_id_period',
            'uq_ack_completions_representative_period',
            'uq_ack_completions_id_period',
            'uq_representative_acknowledgements_completion_requirement',
            'fk_acknowledgements_completion_period',
            'fk_acknowledgements_requirement_period',
        ] as $requiredConstraint) {
            assertBaselineSame(
                true,
                str_contains($migrationSource, '`' . $requiredConstraint . '`'),
                sprintf('Institutional Acknowledgements constraint is missing: %s.', $requiredConstraint)
            );
        }
    });

    $runner->add('all 35 table columns match DATABASE_DESIGN', function (): void {
        $design = file_get_contents(dirname(__DIR__) . '/.ai/12.DATABASE_DESIGN.md');
        if (!is_string($design)) {
            throw new RuntimeException('Unable to read DATABASE_DESIGN.');
        }

        preg_match_all('/^## 4\.\d+ `([^`]+)`\R(.*?)(?=^## 4\.|^# 5\.)/ms', $design, $sections, PREG_SET_ORDER);
        assertBaselineSame(35, count($sections), 'DATABASE_DESIGN must describe exactly 35 physical tables.');

        $migrationSource = implode("\n", baselineMigrationSources());
        foreach ($sections as $section) {
            preg_match_all('/^\| `([^`]+)` \|/m', $section[2], $documentedColumns);
            assertBaselineSame(
                $documentedColumns[1],
                baselineTableColumns($migrationSource, $section[1]),
                sprintf('Columns differ from DATABASE_DESIGN for %s.', $section[1])
            );
        }
    });

    $runner->add('all documented column types and nullability match the migrations', function (): void {
        $design = (string) file_get_contents(dirname(__DIR__) . '/.ai/12.DATABASE_DESIGN.md');
        preg_match_all('/^## 4\.\d+ `([^`]+)`\R(.*?)(?=^## 4\.|^# 5\.)/ms', $design, $sections, PREG_SET_ORDER);
        $migrationSource = implode("\n", baselineMigrationSources());

        foreach ($sections as $section) {
            preg_match_all('/^\| `([^`]+)` \| `([^`]+)` \|/m', $section[2], $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $declaration = baselineColumnDeclaration($migrationSource, $section[1], $row[1]);
                assertBaselineSame(
                    true,
                    baselineDeclarationMatchesDocumentedType($declaration, $row[2]),
                    sprintf('Type or nullability differs for %s.%s.', $section[1], $row[1])
                );
            }
        }
    });

    $runner->add('generated columns use the MariaDB 10.4 persistent syntax', function (): void {
        $migrationSource = preg_replace('/\s+/', ' ', implode("\n", baselineMigrationSources()));
        if (!is_string($migrationSource)) {
            throw new RuntimeException('Unable to normalize migration sources.');
        }

        $expectedDeclarations = [
            "`identification_key` VARCHAR(120) GENERATED ALWAYS AS (IF(`document_type_id` IS NULL OR `document_number` IS NULL, NULL, CONCAT(`document_type_id`, ':', UPPER(TRIM(`document_number`))))) PERSISTENT" => 1,
            "`active_family_representative_key` VARCHAR(50) GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_id`, ':', `representative_id`), NULL)) PERSISTENT" => 2,
            "`active_primary_family_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`ended_at` IS NULL AND `is_primary` = TRUE, `family_id`, NULL)) PERSISTENT" => 1,
            "`active_student_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`ended_at` IS NULL, `student_id`, NULL)) PERSISTENT" => 2,
            "`active_contact_student_key` VARCHAR(50) GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_emergency_contact_id`, ':', `student_id`), NULL)) PERSISTENT" => 1,
            "`active_student_priority_key` VARCHAR(50) GENERATED ALWAYS AS (IF(`ended_at` IS NULL AND `priority` IS NOT NULL, CONCAT(`student_id`, ':', `priority`), NULL)) PERSISTENT" => 1,
            "`active_pickup_student_key` VARCHAR(50) GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_authorized_pickup_id`, ':', `student_id`), NULL)) PERSISTENT" => 1,
        ];

        foreach ($expectedDeclarations as $declaration => $expectedCount) {
            assertBaselineSame(
                $expectedCount,
                substr_count($migrationSource, $declaration),
                sprintf('Generated declaration changed or is missing: %s.', $declaration)
            );
        }

        assertBaselineSame(9, substr_count($migrationSource, 'GENERATED ALWAYS AS'), 'Unexpected generated-column count.');
        assertBaselineSame(9, substr_count($migrationSource, ' PERSISTENT'), 'Every generated column must be PERSISTENT.');
        assertBaselineSame(0, preg_match_all('/\bNULL\s+GENERATED\s+ALWAYS\s+AS\b/i', $migrationSource), 'NULL before AS is incompatible with MariaDB 10.4.');
        assertBaselineSame(0, preg_match_all('/\bDEFAULT\s+NULL\s+GENERATED\s+ALWAYS\s+AS\b/i', $migrationSource), 'DEFAULT NULL before AS is incompatible with MariaDB 10.4.');
        assertBaselineSame(false, str_contains($migrationSource, ' STORED'), 'Generated columns must use the PERSISTENT convention.');

        $indexedColumns = [
            'identification_key' => 1,
            'active_family_representative_key' => 2,
            'active_primary_family_id' => 1,
            'active_student_id' => 2,
            'active_contact_student_key' => 1,
            'active_student_priority_key' => 1,
            'active_pickup_student_key' => 1,
        ];
        foreach ($indexedColumns as $column => $expectedCount) {
            assertBaselineSame(
                $expectedCount,
                preg_match_all('/UNIQUE KEY `[^`]+` \(`' . preg_quote($column, '/') . '`\)/', $migrationSource),
                sprintf('Generated column UNIQUE coverage changed: %s.', $column)
            );
        }
    });

    $runner->add('documented keys, checks and referential actions match the baseline', function (): void {
        $design = (string) file_get_contents(dirname(__DIR__) . '/.ai/12.DATABASE_DESIGN.md');
        $migrationSource = implode("\n", baselineMigrationSources());
        preg_match_all('/`((?:uq|idx|chk)_[a-z0-9_]+)/', $design, $documentedNames);

        foreach (array_unique($documentedNames[1]) as $name) {
            assertBaselineSame(
                true,
                baselineContainsConstraintName($migrationSource, $name),
                sprintf('Documented key or check is missing: %s.', $name)
            );
        }

        assertBaselineSame(58, substr_count($migrationSource, 'REFERENCES `'), 'Unexpected foreign-key count.');
        assertBaselineSame(3, substr_count($migrationSource, 'ON DELETE CASCADE'), 'Only the three snapshot child FKs may cascade.');
        assertBaselineSame(55, substr_count($migrationSource, 'ON DELETE RESTRICT'), 'All non-snapshot FKs must restrict deletion.');
        assertBaselineSame(58, substr_count($migrationSource, 'ON UPDATE RESTRICT'), 'Every FK must restrict key updates.');
    });

    $runner->add('baseline excludes discarded schema and Person-owned family resources', function (): void {
        $allSources = implode("\n", baselineMigrationSources());
        foreach (['nationalities', 'genders', 'preferred_name', 'nationality_id', 'deleted_at'] as $discarded) {
            assertBaselineSame(false, str_contains($allSources, $discarded), sprintf('Discarded schema token remains: %s.', $discarded));
        }

        foreach (['family_emergency_contacts', 'family_authorized_pickups'] as $table) {
            $columns = baselineTableColumns($allSources, $table);
            assertBaselineSame(false, in_array('person_id', $columns, true), sprintf('%s must not own a Person FK.', $table));
        }
    });

    $runner->add('seeders contain only approved baseline status codes', function (): void {
        $statusTypes = (string) file_get_contents(dirname(__DIR__) . '/database/seeders/StatusTypeSeeder.php');
        $statuses = (string) file_get_contents(dirname(__DIR__) . '/database/seeders/StatusSeeder.php');

        foreach (['USER_STATUS', 'GENERAL_STATUS', 'ENROLLMENT_STATUS'] as $code) {
            assertBaselineSame(true, str_contains($statusTypes, $code), sprintf('Missing status type %s.', $code));
        }
        foreach (['ACTIVE', 'DISABLED', 'INACTIVE', 'DRAFT', 'SUBMITTED', 'COMPLETED', 'CANCELLED'] as $code) {
            assertBaselineSame(true, str_contains($statuses, "'code' => '" . $code . "'"), sprintf('Missing status %s.', $code));
        }
        assertBaselineSame(false, str_contains($statusTypes . $statuses, 'PERSON_STATUS'), 'PERSON_STATUS is not approved.');
    });

    $runner->add('application boots with modular Person and Family delivery', function (): void {
        $application = require dirname(__DIR__) . '/bootstrap/app.php';
        assertBaselineSame(true, $application instanceof Application, 'Application bootstrap did not return an Application.');

        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        foreach (['App\\Controllers\\PersonController', 'App\\Controllers\\FamilyController'] as $legacyRoute) {
            assertBaselineSame(false, str_contains($routes, $legacyRoute), sprintf('Legacy route remains: %s.', $legacyRoute));
        }
        assertBaselineSame(
            true,
            str_contains($routes, 'App\\Person\\Http\\PersonController'),
            'Modular Person delivery route is missing.',
        );
        assertBaselineSame(
            true,
            str_contains($routes, 'App\\Family\\Http\\FamilyController'),
            'Modular Family delivery route is missing.',
        );
    });
}

/** @return list<string> */
function baselineMigrationSources(): array
{
    $directory = dirname(__DIR__) . '/database/migrations';
    $entries = scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('Unable to enumerate baseline migrations.');
    }

    $files = [];
    foreach ($entries as $entry) {
        if (preg_match('/^00[2-9]_.+\.php$/', $entry) === 1) {
            $files[] = $directory . '/' . $entry;
        }
    }
    sort($files);

    return array_map(static function (string $file): string {
        $source = file_get_contents($file);
        if (!is_string($source)) {
            throw new RuntimeException(sprintf('Unable to read migration %s.', $file));
        }

        return $source;
    }, $files);
}

/** @return list<string> */
function baselineTableColumns(string $source, string $table): array
{
    $pattern = '/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\n\s*\) ENGINE=/s';
    if (preg_match($pattern, $source, $match) !== 1) {
        if (preg_match("/generalCatalogSql\\('" . preg_quote($table, '/') . "'/", $source) === 1) {
            return ['id', 'code', 'name', 'description', 'is_active', 'created_at', 'updated_at'];
        }

        throw new RuntimeException(sprintf('Table declaration not found: %s.', $table));
    }

    preg_match_all('/^\s*`([^`]+)`\s+/m', $match[1], $columns);

    return $columns[1];
}

/** @return list<string> */
function baselineDeclaredTables(string $source): array
{
    preg_match_all('/CREATE TABLE `([^`]+)`/', $source, $literalTables);
    preg_match_all("/generalCatalogSql\\('([^']+)'/", $source, $generatedTables);

    return array_merge(
        array_values(array_filter($literalTables[1], static fn (string $table): bool => !str_contains($table, '%'))),
        $generatedTables[1]
    );
}

function baselineTableDefinition(string $source, string $table): string
{
    $pattern = '/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\n\s*\) ENGINE=/s';
    if (preg_match($pattern, $source, $match) !== 1) {
        throw new RuntimeException(sprintf('Table declaration not found: %s.', $table));
    }

    return $match[1];
}

function baselineColumnDeclaration(string $source, string $table, string $column): string
{
    if (preg_match("/generalCatalogSql\\('" . preg_quote($table, '/') . "'/", $source) === 1) {
        $generic = [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            'code' => 'VARCHAR(100) NOT NULL',
            'name' => 'VARCHAR(150) NOT NULL',
            'description' => 'VARCHAR(255) NULL DEFAULT NULL',
            'is_active' => 'BOOLEAN NOT NULL DEFAULT TRUE',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];

        return $generic[$column] ?? '';
    }

    $tablePattern = '/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\n\s*\) ENGINE=/s';
    if (preg_match($tablePattern, $source, $tableMatch) !== 1) {
        return '';
    }

    $columnPattern = '/^\s*`' . preg_quote($column, '/') . '`\s+(.*?)(?=,\R)/ms';
    if (preg_match($columnPattern, $tableMatch[1], $columnMatch) !== 1) {
        return '';
    }

    return trim(preg_replace('/\s+/', ' ', $columnMatch[1]) ?? $columnMatch[1]);
}

function baselineContainsConstraintName(string $source, string $name): bool
{
    if (str_contains($source, $name)) {
        return true;
    }

    if (preg_match('/^chk_([a-z_]+)_is_active$/', $name, $match) !== 1) {
        return false;
    }

    return preg_match("/generalCatalogSql\\('" . preg_quote($match[1], '/') . "'/", $source) === 1;
}

function baselineDeclarationMatchesDocumentedType(string $declaration, string $documentedType): bool
{
    $normalizedType = preg_replace('/\s+/', ' ', $documentedType) ?? $documentedType;
    if (str_contains($declaration, $normalizedType)) {
        return true;
    }

    if (!str_contains($declaration, 'GENERATED ALWAYS AS') || !str_ends_with($normalizedType, ' NULL')) {
        return false;
    }

    $generatedStorageType = substr($normalizedType, 0, -5);

    return str_starts_with($declaration, $generatedStorageType . ' GENERATED ALWAYS AS');
}

function assertBaselineSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}
