<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateFamilyRepresentativesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'family_representatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_id');
            $table->foreignId('family_id');
            $table->foreignId('representative_id');
            $table->foreignId('relationship_type_id')->nullable();
            $table->boolean('is_primary');
            $table->boolean('receives_notifications');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index('status_id', 'family_representatives_status_id_idx');
            $table->index(['family_id', 'representative_id'], 'family_representatives_family_representative_unique');
            $table->index('representative_id', 'family_representatives_representative_id_idx');
            $table->foreign('status_id')->references('id')->on('statuses');
            $table->foreign('family_id')->references('id')->on('families');
            $table->foreign('representative_id')->references('id')->on('representatives');
            $table->foreign('relationship_type_id')->references('id')->on('relationship_types');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `family_representatives_family_representative_unique` (`family_id`, `representative_id`)' => 'UNIQUE KEY `family_representatives_family_representative_unique` (`family_id`, `representative_id`)',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('family_representatives');
    }

    public function version(): string
    {
        return '012_create_family_representatives_table';
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
