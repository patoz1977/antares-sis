<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateUsersTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id');
            $table->foreignId('status_id');
            $table->string('username', 100);
            $table->string('email', 255);
            $table->string('password_hash', 255);
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('failed_login_attempts', 5)->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index('person_id', 'users_person_id_unique');
            $table->index('username', 'users_username_unique');
            $table->index('email', 'users_email_unique');
            $table->index('status_id', 'users_status_id_idx');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `users_person_id_unique` (`person_id`)' => 'UNIQUE KEY `users_person_id_unique` (`person_id`)',
            'KEY `users_username_unique` (`username`)' => 'UNIQUE KEY `users_username_unique` (`username`)',
            'KEY `users_email_unique` (`email`)' => 'UNIQUE KEY `users_email_unique` (`email`)',
            '`failed_login_attempts` INT(5) UNSIGNED NOT NULL DEFAULT 0' => '`failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('users');
    }

    public function version(): string
    {
        return '005_create_users_table';
    }

    private function createTable(PDO $connection, string $table, callable $callback, array $replacements = []): void
    {
        $builder = new \Core\Database\Schema\SchemaBuilder();
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $compileMethod = new \ReflectionMethod($builder, 'compileCreateTable');
        $compileMethod->setAccessible(true);
        $sql = $compileMethod->invoke($builder, $blueprint);

        foreach ($replacements as $search => $replace) {
            $sql = str_replace($search, $replace, $sql);
        }

        $connection->exec($sql);
    }
}
