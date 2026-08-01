<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Schema;

final class CreateUsersTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `users` (
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
                CONSTRAINT `fk_users_person`
                    FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `fk_users_status`
                    FOREIGN KEY (`status_id`) REFERENCES `statuses` (`id`)
                    ON DELETE RESTRICT ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('users');
    }

    public function version(): string
    {
        return '005_create_users_table';
    }
}
