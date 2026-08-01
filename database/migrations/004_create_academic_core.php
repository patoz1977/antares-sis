<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateAcademicCore extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `grades` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `sort_order` SMALLINT UNSIGNED NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_grades_code` (`code`),
                    UNIQUE KEY `uq_grades_sort_order` (`sort_order`),
                    KEY `idx_grades_status_sort_order` (`status_id`, `sort_order`),
                    CONSTRAINT `chk_grades_sort_order` CHECK (`sort_order` > 0),
                    CONSTRAINT `fk_grades_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `sections` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_sections_code` (`code`),
                    KEY `idx_sections_status_name` (`status_id`, `name`),
                    CONSTRAINT `fk_sections_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `academic_periods` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `starts_on` DATE NOT NULL,
                    `ends_on` DATE NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_academic_periods_code` (`code`),
                    KEY `idx_academic_periods_status_dates` (`status_id`, `starts_on`, `ends_on`),
                    CONSTRAINT `chk_academic_periods_dates` CHECK (`ends_on` >= `starts_on`),
                    CONSTRAINT `fk_academic_periods_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, ['academic_periods', 'sections', 'grades']);
    }

    public function version(): string
    {
        return '004_create_academic_core';
    }
}
