<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateStatusSchema extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `status_types` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `description` VARCHAR(255) NULL DEFAULT NULL,
                    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_status_types_code` (`code`),
                    CONSTRAINT `chk_status_types_is_active` CHECK (`is_active` IN (0, 1))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `statuses` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `status_type_id` BIGINT UNSIGNED NOT NULL,
                    `code` VARCHAR(100) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `description` VARCHAR(255) NULL DEFAULT NULL,
                    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_statuses_type_code` (`status_type_id`, `code`),
                    KEY `idx_statuses_type_active_order` (`status_type_id`, `is_active`, `sort_order`),
                    CONSTRAINT `chk_statuses_is_active` CHECK (`is_active` IN (0, 1)),
                    CONSTRAINT `fk_statuses_type` FOREIGN KEY (`status_type_id`)
                        REFERENCES `status_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, ['statuses', 'status_types']);
    }

    public function version(): string
    {
        return '002_create_status_schema';
    }
}
