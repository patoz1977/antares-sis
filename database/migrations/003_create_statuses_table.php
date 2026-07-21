<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreateStatusesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS statuses ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'status_type_id BIGINT UNSIGNED NOT NULL,'
            . 'code VARCHAR(50) NOT NULL,'
            . 'name VARCHAR(100) NOT NULL,'
            . 'description VARCHAR(255) DEFAULT NULL,'
            . 'display_order SMALLINT UNSIGNED NOT NULL,'
            . 'color VARCHAR(20) DEFAULT NULL,'
            . 'is_default BOOLEAN NOT NULL DEFAULT FALSE,'
            . 'is_terminal BOOLEAN NOT NULL DEFAULT FALSE,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'deleted_at TIMESTAMP NULL DEFAULT NULL,'
            . 'created_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'updated_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'deleted_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY statuses_type_code_unique (status_type_id, code),'
            . 'KEY statuses_status_type_id_idx (status_type_id),'
            . 'KEY statuses_display_order_idx (display_order)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS statuses');
    }

    public function version(): string
    {
        return '003_create_statuses_table';
    }
}
