<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateReferenceCatalogs extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $this->createTables($connection, [
            $this->generalCatalogSql('document_types', 'uq_document_types_code', 'idx_document_types_active_name'),
            $this->generalCatalogSql('sexes', 'uq_sexes_code', 'idx_sexes_active_name'),
            $this->generalCatalogSql('marital_statuses', 'uq_marital_statuses_code', 'idx_marital_statuses_active_name'),
            $this->generalCatalogSql('education_levels', 'uq_education_levels_code', 'idx_education_levels_active_name'),
            $this->generalCatalogSql('relationship_types', 'uq_relationship_types_code', 'idx_relationship_types_active_name'),
            <<<'SQL'
                CREATE TABLE `provinces` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `code` VARCHAR(20) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_provinces_code` (`code`),
                    KEY `idx_provinces_active_name` (`is_active`, `name`),
                    CONSTRAINT `chk_provinces_is_active` CHECK (`is_active` IN (0, 1))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `cantons` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `province_id` BIGINT UNSIGNED NOT NULL,
                    `code` VARCHAR(20) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_cantons_province_code` (`province_id`, `code`),
                    UNIQUE KEY `uq_cantons_id_province` (`id`, `province_id`),
                    KEY `idx_cantons_province_active_name` (`province_id`, `is_active`, `name`),
                    CONSTRAINT `chk_cantons_is_active` CHECK (`is_active` IN (0, 1)),
                    CONSTRAINT `fk_cantons_province` FOREIGN KEY (`province_id`)
                        REFERENCES `provinces` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
            <<<'SQL'
                CREATE TABLE `parishes` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `canton_id` BIGINT UNSIGNED NOT NULL,
                    `province_id` BIGINT UNSIGNED NOT NULL,
                    `code` VARCHAR(20) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_parishes_canton_code` (`canton_id`, `code`),
                    UNIQUE KEY `uq_parishes_id_canton_province` (`id`, `canton_id`, `province_id`),
                    KEY `idx_parishes_canton_active_name` (`canton_id`, `is_active`, `name`),
                    CONSTRAINT `chk_parishes_is_active` CHECK (`is_active` IN (0, 1)),
                    CONSTRAINT `fk_parishes_canton_province` FOREIGN KEY (`canton_id`, `province_id`)
                        REFERENCES `cantons` (`id`, `province_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        ]);
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, [
            'parishes', 'cantons', 'provinces', 'relationship_types',
            'education_levels', 'marital_statuses', 'sexes', 'document_types',
        ]);
    }

    public function version(): string
    {
        return '003_create_reference_catalogs';
    }

    private function generalCatalogSql(string $table, string $unique, string $index): string
    {
        return sprintf(
            'CREATE TABLE `%1$s` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`code` VARCHAR(100) NOT NULL,'
            . '`name` VARCHAR(150) NOT NULL,'
            . '`description` VARCHAR(255) NULL DEFAULT NULL,'
            . '`is_active` BOOLEAN NOT NULL DEFAULT TRUE,'
            . '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `%2$s` (`code`),'
            . 'KEY `%3$s` (`is_active`, `name`),'
            . 'CONSTRAINT `chk_%1$s_is_active` CHECK (`is_active` IN (0, 1))'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $table,
            $unique,
            $index,
        );
    }
}
