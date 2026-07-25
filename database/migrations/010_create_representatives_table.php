<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateRepresentativesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'representatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id');
            $table->foreignId('status_id');
            $table->string('occupation', 150)->nullable();
            $table->string('company', 150)->nullable();
            $table->string('work_phone', 30)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index('person_id', 'representatives_person_id_unique');
            $table->index('status_id', 'representatives_status_id_idx');
            $table->foreign('person_id')->references('id')->on('persons');
            $table->foreign('status_id')->references('id')->on('statuses');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `representatives_person_id_unique` (`person_id`)' => 'UNIQUE KEY `representatives_person_id_unique` (`person_id`)',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('representatives');
    }

    public function version(): string
    {
        return '010_create_representatives_table';
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
