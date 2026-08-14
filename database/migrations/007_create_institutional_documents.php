<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateInstitutionalDocuments extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `acknowledgement_requirements` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `academic_period_id` BIGINT UNSIGNED NOT NULL,
                    `title` VARCHAR(200) NOT NULL,
                    `url` VARCHAR(500) NOT NULL,
                    `official_reference` VARCHAR(255) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_ack_requirements_id_period` (`id`, `academic_period_id`),
                    KEY `idx_ack_requirements_period_status_title` (`academic_period_id`, `status_id`, `title`),
                    CONSTRAINT `chk_ack_requirements_title` CHECK (NULLIF(TRIM(`title`), '') IS NOT NULL),
                    CONSTRAINT `chk_ack_requirements_url` CHECK (NULLIF(TRIM(`url`), '') IS NOT NULL),
                    CONSTRAINT `chk_ack_requirements_official_reference` CHECK (`official_reference` IS NULL OR NULLIF(TRIM(`official_reference`), '') IS NOT NULL),
                    CONSTRAINT `fk_ack_requirements_period` FOREIGN KEY (`academic_period_id`)
                        REFERENCES `academic_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_ack_requirements_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `representative_acknowledgement_completions` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `representative_id` BIGINT UNSIGNED NOT NULL,
                    `academic_period_id` BIGINT UNSIGNED NOT NULL,
                    `completed_at` TIMESTAMP NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_ack_completions_representative_period` (`representative_id`, `academic_period_id`),
                    UNIQUE KEY `uq_ack_completions_id_period` (`id`, `academic_period_id`),
                    CONSTRAINT `fk_ack_completions_representative` FOREIGN KEY (`representative_id`)
                        REFERENCES `representatives` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_ack_completions_period` FOREIGN KEY (`academic_period_id`)
                        REFERENCES `academic_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `representative_acknowledgements` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `representative_acknowledgement_completion_id` BIGINT UNSIGNED NOT NULL,
                    `acknowledgement_requirement_id` BIGINT UNSIGNED NOT NULL,
                    `academic_period_id` BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_representative_acknowledgements_completion_requirement` (`representative_acknowledgement_completion_id`, `acknowledgement_requirement_id`),
                    KEY `idx_representative_acknowledgements_requirement` (`acknowledgement_requirement_id`, `academic_period_id`),
                    CONSTRAINT `fk_acknowledgements_completion_period` FOREIGN KEY (`representative_acknowledgement_completion_id`, `academic_period_id`)
                        REFERENCES `representative_acknowledgement_completions` (`id`, `academic_period_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_acknowledgements_requirement_period` FOREIGN KEY (`acknowledgement_requirement_id`, `academic_period_id`)
                        REFERENCES `acknowledgement_requirements` (`id`, `academic_period_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, [
            'representative_acknowledgements', 'representative_acknowledgement_completions',
            'acknowledgement_requirements',
        ]);
    }

    public function version(): string
    {
        return '007_create_institutional_documents';
    }
}
