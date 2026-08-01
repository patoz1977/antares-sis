<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateFamilyManagement extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `family_representatives` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `representative_id` BIGINT UNSIGNED NOT NULL,
                    `relationship_type_id` BIGINT UNSIGNED NOT NULL,
                    `is_primary` BOOLEAN NOT NULL DEFAULT FALSE,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_family_representative_key` VARCHAR(50)
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_id`, ':', `representative_id`), NULL)) PERSISTENT,
                    `active_primary_family_id` BIGINT UNSIGNED
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL AND `is_primary` = TRUE, `family_id`, NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_family_representatives_history` (`family_id`, `representative_id`, `started_at`),
                    UNIQUE KEY `uq_family_representatives_active` (`active_family_representative_key`),
                    UNIQUE KEY `uq_family_representatives_primary` (`active_primary_family_id`),
                    KEY `idx_family_representatives_representative_active` (`representative_id`, `ended_at`),
                    KEY `idx_family_representatives_family_active` (`family_id`, `ended_at`),
                    CONSTRAINT `chk_family_representatives_primary` CHECK (`is_primary` IN (0, 1)),
                    CONSTRAINT `chk_family_representatives_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_family_representatives_family` FOREIGN KEY (`family_id`)
                        REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_representatives_representative` FOREIGN KEY (`representative_id`)
                        REFERENCES `representatives` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_representatives_relationship` FOREIGN KEY (`relationship_type_id`)
                        REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `family_students` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `student_id` BIGINT UNSIGNED NOT NULL,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_student_id` BIGINT UNSIGNED
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, `student_id`, NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_family_students_history` (`family_id`, `student_id`, `started_at`),
                    UNIQUE KEY `uq_family_students_active_student` (`active_student_id`),
                    KEY `idx_family_students_family_active` (`family_id`, `ended_at`, `student_id`),
                    CONSTRAINT `chk_family_students_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_family_students_family` FOREIGN KEY (`family_id`)
                        REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_students_student` FOREIGN KEY (`student_id`)
                        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `family_addresses` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `label` VARCHAR(100) NOT NULL,
                    `province_id` BIGINT UNSIGNED NOT NULL,
                    `canton_id` BIGINT UNSIGNED NOT NULL,
                    `parish_id` BIGINT UNSIGNED NOT NULL,
                    `main_street` VARCHAR(200) NOT NULL,
                    `street_number` VARCHAR(50) NULL DEFAULT NULL,
                    `secondary_street` VARCHAR(200) NULL DEFAULT NULL,
                    `sector` VARCHAR(150) NULL DEFAULT NULL,
                    `reference` VARCHAR(255) NULL DEFAULT NULL,
                    `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
                    `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_family_addresses_id_family` (`id`, `family_id`),
                    KEY `idx_family_addresses_family_status` (`family_id`, `status_id`),
                    KEY `idx_family_addresses_province` (`province_id`),
                    KEY `idx_family_addresses_canton_province` (`canton_id`, `province_id`),
                    KEY `idx_family_addresses_parish_canton_province` (`parish_id`, `canton_id`, `province_id`),
                    CONSTRAINT `chk_family_addresses_coordinates_pair`
                        CHECK ((`latitude` IS NULL) = (`longitude` IS NULL)),
                    CONSTRAINT `chk_family_addresses_latitude`
                        CHECK (`latitude` IS NULL OR `latitude` BETWEEN -90 AND 90),
                    CONSTRAINT `chk_family_addresses_longitude`
                        CHECK (`longitude` IS NULL OR `longitude` BETWEEN -180 AND 180),
                    CONSTRAINT `fk_family_addresses_family` FOREIGN KEY (`family_id`)
                        REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_addresses_province` FOREIGN KEY (`province_id`)
                        REFERENCES `provinces` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_addresses_canton` FOREIGN KEY (`canton_id`, `province_id`)
                        REFERENCES `cantons` (`id`, `province_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_addresses_parish` FOREIGN KEY (`parish_id`, `canton_id`, `province_id`)
                        REFERENCES `parishes` (`id`, `canton_id`, `province_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_family_addresses_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `representative_address_assignments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `family_address_id` BIGINT UNSIGNED NOT NULL,
                    `representative_id` BIGINT UNSIGNED NOT NULL,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_family_representative_key` VARCHAR(50)
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_id`, ':', `representative_id`), NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_representative_address_history` (`family_id`, `representative_id`, `started_at`),
                    UNIQUE KEY `uq_representative_address_active` (`active_family_representative_key`),
                    KEY `idx_representative_addresses_address_active` (`family_address_id`, `ended_at`),
                    KEY `idx_representative_addresses_rep_active` (`representative_id`, `ended_at`),
                    CONSTRAINT `chk_representative_address_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_representative_address_resource` FOREIGN KEY (`family_address_id`, `family_id`)
                        REFERENCES `family_addresses` (`id`, `family_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_representative_address_representative` FOREIGN KEY (`representative_id`)
                        REFERENCES `representatives` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `student_address_assignments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `family_address_id` BIGINT UNSIGNED NOT NULL,
                    `student_id` BIGINT UNSIGNED NOT NULL,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_student_id` BIGINT UNSIGNED
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, `student_id`, NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_student_address_history` (`family_id`, `student_id`, `started_at`),
                    UNIQUE KEY `uq_student_address_active` (`active_student_id`),
                    KEY `idx_student_addresses_address_active` (`family_address_id`, `ended_at`),
                    KEY `idx_student_addresses_family_active` (`family_id`, `ended_at`),
                    CONSTRAINT `chk_student_address_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_student_address_resource` FOREIGN KEY (`family_address_id`, `family_id`)
                        REFERENCES `family_addresses` (`id`, `family_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_student_address_student` FOREIGN KEY (`student_id`)
                        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `family_emergency_contacts` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `names` VARCHAR(200) NOT NULL,
                    `relationship_type_id` BIGINT UNSIGNED NOT NULL,
                    `mobile_phone` VARCHAR(30) NOT NULL,
                    `phone` VARCHAR(30) NULL DEFAULT NULL,
                    `email` VARCHAR(254) NULL DEFAULT NULL,
                    `observations` VARCHAR(500) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_family_emergency_contacts_id_family` (`id`, `family_id`),
                    KEY `idx_emergency_contacts_family_status` (`family_id`, `status_id`),
                    KEY `idx_emergency_contacts_relationship` (`relationship_type_id`),
                    CONSTRAINT `fk_emergency_contacts_family` FOREIGN KEY (`family_id`)
                        REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_emergency_contacts_relationship` FOREIGN KEY (`relationship_type_id`)
                        REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_emergency_contacts_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `emergency_contact_assignments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `family_emergency_contact_id` BIGINT UNSIGNED NOT NULL,
                    `student_id` BIGINT UNSIGNED NOT NULL,
                    `priority` INT UNSIGNED NULL DEFAULT NULL,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_contact_student_key` VARCHAR(50)
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_emergency_contact_id`, ':', `student_id`), NULL)) PERSISTENT,
                    `active_student_priority_key` VARCHAR(50)
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL AND `priority` IS NOT NULL, CONCAT(`student_id`, ':', `priority`), NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_emergency_assignment_active` (`active_contact_student_key`),
                    UNIQUE KEY `uq_emergency_priority_active` (`active_student_priority_key`),
                    UNIQUE KEY `uq_emergency_assignment_history` (`family_emergency_contact_id`, `student_id`, `started_at`),
                    KEY `idx_emergency_assignments_student_active_order` (`student_id`, `ended_at`, `priority`, `created_at`),
                    KEY `idx_emergency_assignments_family_active` (`family_id`, `ended_at`),
                    CONSTRAINT `chk_emergency_assignment_priority` CHECK (`priority` IS NULL OR `priority` > 0),
                    CONSTRAINT `chk_emergency_assignment_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_emergency_assignment_contact` FOREIGN KEY (`family_emergency_contact_id`, `family_id`)
                        REFERENCES `family_emergency_contacts` (`id`, `family_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_emergency_assignment_student` FOREIGN KEY (`student_id`)
                        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `family_authorized_pickups` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `names` VARCHAR(200) NOT NULL,
                    `relationship_type_id` BIGINT UNSIGNED NOT NULL,
                    `mobile_phone` VARCHAR(30) NOT NULL,
                    `phone` VARCHAR(30) NULL DEFAULT NULL,
                    `document_type_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `document_number` VARCHAR(50) NULL DEFAULT NULL,
                    `observations` VARCHAR(500) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_family_authorized_pickups_id_family` (`id`, `family_id`),
                    KEY `idx_pickups_family_status` (`family_id`, `status_id`),
                    KEY `idx_pickups_relationship` (`relationship_type_id`),
                    KEY `idx_pickups_document` (`document_type_id`, `document_number`),
                    CONSTRAINT `chk_pickup_document_pair`
                        CHECK ((`document_type_id` IS NULL) = (`document_number` IS NULL)),
                    CONSTRAINT `fk_pickups_family` FOREIGN KEY (`family_id`)
                        REFERENCES `families` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_pickups_relationship` FOREIGN KEY (`relationship_type_id`)
                        REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_pickups_document_type` FOREIGN KEY (`document_type_id`)
                        REFERENCES `document_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_pickups_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `authorized_pickup_assignments` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `family_id` BIGINT UNSIGNED NOT NULL,
                    `family_authorized_pickup_id` BIGINT UNSIGNED NOT NULL,
                    `student_id` BIGINT UNSIGNED NOT NULL,
                    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ended_at` TIMESTAMP NULL DEFAULT NULL,
                    `active_pickup_student_key` VARCHAR(50)
                        GENERATED ALWAYS AS (IF(`ended_at` IS NULL, CONCAT(`family_authorized_pickup_id`, ':', `student_id`), NULL)) PERSISTENT,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_pickup_assignment_active` (`active_pickup_student_key`),
                    UNIQUE KEY `uq_pickup_assignment_history` (`family_authorized_pickup_id`, `student_id`, `started_at`),
                    KEY `idx_pickup_assignments_student_active` (`student_id`, `ended_at`),
                    KEY `idx_pickup_assignments_family_active` (`family_id`, `ended_at`),
                    CONSTRAINT `chk_pickup_assignment_dates` CHECK (`ended_at` IS NULL OR `ended_at` >= `started_at`),
                    CONSTRAINT `fk_pickup_assignment_resource` FOREIGN KEY (`family_authorized_pickup_id`, `family_id`)
                        REFERENCES `family_authorized_pickups` (`id`, `family_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_pickup_assignment_student` FOREIGN KEY (`student_id`)
                        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, [
            'authorized_pickup_assignments', 'family_authorized_pickups',
            'emergency_contact_assignments', 'family_emergency_contacts',
            'student_address_assignments', 'representative_address_assignments',
            'family_addresses', 'family_students', 'family_representatives',
        ]);
    }

    public function version(): string
    {
        return '006_create_family_management';
    }
}
