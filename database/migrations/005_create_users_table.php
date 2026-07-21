<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreateUsersTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS users ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'person_id BIGINT UNSIGNED NOT NULL,'
            . 'status_id BIGINT UNSIGNED NOT NULL,'
            . 'username VARCHAR(100) NOT NULL,'
            . 'email VARCHAR(255) NOT NULL,'
            . 'password_hash VARCHAR(255) NOT NULL,'
            . 'password_changed_at TIMESTAMP NULL DEFAULT NULL,'
            . 'last_login_at TIMESTAMP NULL DEFAULT NULL,'
            . 'failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'locked_until TIMESTAMP NULL DEFAULT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'deleted_at TIMESTAMP NULL DEFAULT NULL,'
            . 'created_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'updated_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'deleted_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY users_person_id_unique (person_id),'
            . 'UNIQUE KEY users_username_unique (username),'
            . 'UNIQUE KEY users_email_unique (email),'
            . 'KEY users_status_id_idx (status_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS users');
    }

    public function version(): string
    {
        return '005_create_users_table';
    }
}
