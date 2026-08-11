<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateSubmissionSnapshots extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `enrollment_submission_snapshots` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `enrollment_id` BIGINT UNSIGNED NOT NULL,
                    `created_by_representative_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_submission_snapshots_enrollment` (`enrollment_id`),
                    KEY `idx_submission_snapshots_creator_date` (`created_by_representative_id`, `created_at`),
                    CONSTRAINT `fk_submission_snapshots_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_submission_snapshots_creator` FOREIGN KEY (`created_by_representative_id`) REFERENCES `representatives` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `snapshot_addresses` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `enrollment_submission_snapshot_id` BIGINT UNSIGNED NOT NULL,
                    `label` VARCHAR(100) NOT NULL,
                    `main_street` VARCHAR(200) NOT NULL,
                    `street_number` VARCHAR(50) NULL DEFAULT NULL,
                    `secondary_street` VARCHAR(200) NULL DEFAULT NULL,
                    `sector` VARCHAR(150) NULL DEFAULT NULL,
                    `reference` VARCHAR(255) NULL DEFAULT NULL,
                    `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
                    `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_snapshot_addresses_root` (`enrollment_submission_snapshot_id`),
                    CONSTRAINT `chk_snapshot_addresses_coordinates` CHECK ((`latitude` IS NULL AND `longitude` IS NULL) OR (`latitude` IS NOT NULL AND `longitude` IS NOT NULL)),
                    CONSTRAINT `chk_snapshot_addresses_latitude` CHECK (`latitude` IS NULL OR `latitude` BETWEEN -90 AND 90),
                    CONSTRAINT `chk_snapshot_addresses_longitude` CHECK (`longitude` IS NULL OR `longitude` BETWEEN -180 AND 180),
                    CONSTRAINT `fk_snapshot_addresses_root` FOREIGN KEY (`enrollment_submission_snapshot_id`) REFERENCES `enrollment_submission_snapshots` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `snapshot_emergency_contacts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `enrollment_submission_snapshot_id` BIGINT UNSIGNED NOT NULL,
                    `names` VARCHAR(200) NOT NULL,
                    `relationship_type_code` VARCHAR(100) NOT NULL,
                    `relationship_type_name` VARCHAR(150) NOT NULL,
                    `mobile_phone` VARCHAR(30) NOT NULL,
                    `phone` VARCHAR(30) NULL DEFAULT NULL,
                    `email` VARCHAR(254) NULL DEFAULT NULL,
                    `observations` VARCHAR(500) NULL DEFAULT NULL,
                    `priority` INT UNSIGNED NULL DEFAULT NULL,
                    `sort_order` INT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_snapshot_emergency_order` (`enrollment_submission_snapshot_id`, `sort_order`),
                    CONSTRAINT `chk_snapshot_emergency_priority` CHECK (`priority` IS NULL OR `priority` > 0),
                    CONSTRAINT `chk_snapshot_emergency_sort_order` CHECK (`sort_order` > 0),
                    CONSTRAINT `fk_snapshot_emergency_root` FOREIGN KEY (`enrollment_submission_snapshot_id`) REFERENCES `enrollment_submission_snapshots` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `snapshot_authorized_pickups` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `enrollment_submission_snapshot_id` BIGINT UNSIGNED NOT NULL,
                    `names` VARCHAR(200) NOT NULL,
                    `relationship_type_code` VARCHAR(100) NOT NULL,
                    `relationship_type_name` VARCHAR(150) NOT NULL,
                    `mobile_phone` VARCHAR(30) NOT NULL,
                    `phone` VARCHAR(30) NULL DEFAULT NULL,
                    `document_type_code` VARCHAR(100) NOT NULL,
                    `document_type_name` VARCHAR(150) NOT NULL,
                    `document_number` VARCHAR(50) NOT NULL,
                    `observations` VARCHAR(500) NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_snapshot_pickups_root` (`enrollment_submission_snapshot_id`),
                    CONSTRAINT `chk_snapshot_pickups_document_number` CHECK (NULLIF(TRIM(`document_number`), '') IS NOT NULL),
                    CONSTRAINT `fk_snapshot_pickups_root` FOREIGN KEY (`enrollment_submission_snapshot_id`) REFERENCES `enrollment_submission_snapshots` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, [
            'snapshot_authorized_pickups', 'snapshot_emergency_contacts',
            'snapshot_addresses', 'enrollment_submission_snapshots',
        ]);
    }

    public function version(): string
    {
        return '009_create_submission_snapshots';
    }
}
