<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreateStatusTypesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS status_types ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'code VARCHAR(50) NOT NULL,'
            . 'name VARCHAR(100) NOT NULL,'
            . 'description VARCHAR(255) DEFAULT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'deleted_at TIMESTAMP NULL DEFAULT NULL,'
            . 'created_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'updated_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'deleted_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY status_types_code_unique (code),'
            . 'UNIQUE KEY status_types_name_unique (name)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS status_types');
    }

    public function version(): string
    {
        return '002_create_status_types_table';
    }
}
