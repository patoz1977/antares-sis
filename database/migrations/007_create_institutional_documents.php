<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateInstitutionalDocuments extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            <<<'SQL'
                CREATE TABLE `institutional_documents` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(200) NOT NULL,
                    `description` VARCHAR(500) NULL DEFAULT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_institutional_documents_status_name` (`status_id`, `name`),
                    CONSTRAINT `fk_institutional_documents_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `institutional_document_versions` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `institutional_document_id` BIGINT UNSIGNED NOT NULL,
                    `version` VARCHAR(50) NOT NULL,
                    `file_reference` VARCHAR(500) NOT NULL,
                    `published_at` TIMESTAMP NOT NULL,
                    `status_id` BIGINT UNSIGNED NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_document_versions_document_version` (`institutional_document_id`, `version`),
                    KEY `idx_document_versions_document_published` (`institutional_document_id`, `published_at`),
                    KEY `idx_document_versions_status` (`status_id`),
                    CONSTRAINT `fk_document_versions_document` FOREIGN KEY (`institutional_document_id`)
                        REFERENCES `institutional_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_document_versions_status` FOREIGN KEY (`status_id`)
                        REFERENCES `statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `document_requirements` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `institutional_document_version_id` BIGINT UNSIGNED NOT NULL,
                    `academic_period_id` BIGINT UNSIGNED NOT NULL,
                    `is_required` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_document_requirements_version_period` (`institutional_document_version_id`, `academic_period_id`),
                    KEY `idx_document_requirements_period_required` (`academic_period_id`, `is_required`),
                    CONSTRAINT `chk_document_requirements_required` CHECK (`is_required` IN (0, 1)),
                    CONSTRAINT `fk_document_requirements_version` FOREIGN KEY (`institutional_document_version_id`)
                        REFERENCES `institutional_document_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT `fk_document_requirements_period` FOREIGN KEY (`academic_period_id`)
                        REFERENCES `academic_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, [
            'document_requirements', 'institutional_document_versions', 'institutional_documents',
        ]);
    }

    public function version(): string
    {
        return '007_create_institutional_documents';
    }
}
