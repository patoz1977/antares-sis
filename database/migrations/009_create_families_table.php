<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateFamiliesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'families', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_id');
            $table->string('family_code', 30);
            $table->unique();
            $table->string('name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index('status_id', 'families_status_id_idx');
            $table->foreign('status_id')->references('id')->on('statuses');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('families');
    }

    public function version(): string
    {
        return '009_create_families_table';
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
