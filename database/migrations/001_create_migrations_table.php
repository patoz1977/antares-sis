<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreateMigrationsTable extends Migration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'migration VARCHAR(255) NOT NULL,'
            . 'batch INT NOT NULL,'
            . 'executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY migration_unique (migration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE IF EXISTS migrations');
    }

    public function version(): string
    {
        return '001_create_migrations_table';
    }
}
