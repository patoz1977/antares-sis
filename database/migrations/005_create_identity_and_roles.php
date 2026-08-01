<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateIdentityAndRoles extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `persons` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `first_name` VARCHAR(100) NOT NULL,
                    `middle_name` VARCHAR(100) NULL DEFAULT NULL,
                    `first_surname` VARCHAR(100) NOT NULL,
                    `second_surname` VARCHAR(100) NULL DEFAULT NULL,
                    `document_type_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `document_number` VARCHAR(50) NULL DEFAULT NULL,
                    `identification_key` VARCHAR(120)
                        GENERATED ALWAYS AS (IF(`document_type_id` IS NULL OR `document_number` IS NULL, NULL, CONCAT(`document_type_id`, ':', UPPER(TRIM(`document_number`))))) PERSISTENT,
                    `birth_date` DATE NOT NULL,
                    `sex_id` BIGINT UNSIGNED NOT NULL,
                    `marital_status_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `education_level_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                    `email` VARCHAR(254) NULL DEFAULT NULL,
                    `mobile_phone` VARCHAR(30) NULL DEFAULT NULL,
                    `landline_phone` VARCHAR(30) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_persons_identification_key` (`identification_key`),
                    KEY `idx_persons_name` (`first_surname`, `second_surname`, `first_name`),
                    KEY `idx_persons_document_type` (`document_type_id`),
                    KEY `idx_persons_sex` (`sex_id`),
                    KEY `idx_persons_marital_status` (`marital_status_id`),
                    KEY `idx_persons_education_level` (`education_level_id`),
                    KEY `idx_persons_status` (`status_id`),
                    CONSTRAINT `chk_persons_identification_pair`
                        CHECK ((`document_type_id` IS NULL) = (`document_number` IS NULL)),
                    CONSTRAINT `fk_persons_document_type` FOREIGN KEY (`document_type_id`)
                        REFERENCES `document_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_persons_sex` FOREIGN KEY (`sex_id`)
                        REFERENCES `sexes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_persons_marital_status` FOREIGN KEY (`marital_status_id`)
                        REFERENCES `marital_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_persons_education_level` FOREIGN KEY (`education_level_id`)
                        REFERENCES `education_levels` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_persons_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `users` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `person_id` BIGINT UNSIGNED NOT NULL,
                    `login_identifier` VARCHAR(254) NOT NULL,
                    `normalized_login_identifier` VARCHAR(254) NOT NULL,
                    `password_hash` VARCHAR(255) NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `last_access_at` TIMESTAMP NULL DEFAULT NULL,
                    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `locked_at` TIMESTAMP NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_users_person` (`person_id`),
                    UNIQUE KEY `uq_users_normalized_login` (`normalized_login_identifier`),
                    KEY `idx_users_status_locked` (`status_id`, `locked_at`),
                    CONSTRAINT `chk_users_normalized_login`
                        CHECK (`normalized_login_identifier` = LOWER(TRIM(`normalized_login_identifier`))),
                    CONSTRAINT `fk_users_person` FOREIGN KEY (`person_id`)
                        REFERENCES `persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_users_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `representatives` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `person_id` BIGINT UNSIGNED NOT NULL,
                    `occupation` VARCHAR(150) NULL DEFAULT NULL,
                    `company` VARCHAR(150) NULL DEFAULT NULL,
                    `position` VARCHAR(150) NULL DEFAULT NULL,
                    `work_phone` VARCHAR(30) NULL DEFAULT NULL,
                    `work_email` VARCHAR(254) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_representatives_person` (`person_id`),
                    KEY `idx_representatives_status` (`status_id`),
                    CONSTRAINT `fk_representatives_person` FOREIGN KEY (`person_id`)
                        REFERENCES `persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_representatives_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `students` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `person_id` BIGINT UNSIGNED NOT NULL,
                    `institutional_code` VARCHAR(100) NOT NULL,
                    `admission_date` DATE NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_students_person` (`person_id`),
                    UNIQUE KEY `uq_students_institutional_code` (`institutional_code`),
                    KEY `idx_students_status_admission` (`status_id`, `admission_date`),
                    CONSTRAINT `fk_students_person` FOREIGN KEY (`person_id`)
                        REFERENCES `persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_students_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `families` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `display_name` VARCHAR(200) NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_families_status_name` (`status_id`, `display_name`),
                    CONSTRAINT `fk_families_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, ['families', 'students', 'representatives', 'users', 'persons']);
    }

    public function version(): string
    {
        return '005_create_identity_and_roles';
    }
}
