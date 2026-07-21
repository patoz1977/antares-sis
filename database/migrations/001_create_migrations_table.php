<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateMigrationsTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration', 255);
            $table->integer('batch');
            $table->timestamp('executed_at')->default('CURRENT_TIMESTAMP');
            $table->index('migration', 'migration_unique');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `migration_unique` (`migration`)' => 'UNIQUE KEY `migration_unique` (`migration`)',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('migrations');
    }

    public function version(): string
    {
        return '001_create_migrations_table';
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
