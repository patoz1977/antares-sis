<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreatePersonsTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS persons ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'status_id BIGINT UNSIGNED NOT NULL,'
            . 'document_type_id BIGINT UNSIGNED NOT NULL,'
            . 'document_number VARCHAR(50) NOT NULL,'
            . 'first_name VARCHAR(100) NOT NULL,'
            . 'middle_name VARCHAR(100) DEFAULT NULL,'
            . 'last_name VARCHAR(100) NOT NULL,'
            . 'second_last_name VARCHAR(100) DEFAULT NULL,'
            . 'preferred_name VARCHAR(100) DEFAULT NULL,'
            . 'birth_date DATE DEFAULT NULL,'
            . 'gender_id BIGINT UNSIGNED DEFAULT NULL,'
            . 'nationality_id BIGINT UNSIGNED DEFAULT NULL,'
            . 'email VARCHAR(255) DEFAULT NULL,'
            . 'mobile_phone VARCHAR(30) DEFAULT NULL,'
            . 'home_phone VARCHAR(30) DEFAULT NULL,'
            . 'address VARCHAR(255) DEFAULT NULL,'
            . 'notes TEXT DEFAULT NULL,'
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'deleted_at TIMESTAMP NULL DEFAULT NULL,'
            . 'created_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'updated_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'deleted_by BIGINT UNSIGNED DEFAULT NULL,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY persons_document_unique (document_type_id, document_number),'
            . 'KEY persons_last_name_idx (last_name),'
            . 'KEY persons_email_idx (email),'
            . 'KEY persons_status_id_idx (status_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS persons');
    }

    public function version(): string
    {
        return '004_create_persons_table';
    }
}
