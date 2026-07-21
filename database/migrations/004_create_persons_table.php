<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreatePersonsTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'persons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_id');
            $table->foreignId('document_type_id');
            $table->string('document_number', 50);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('second_last_name', 100)->nullable();
            $table->string('preferred_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->foreignId('gender_id')->nullable();
            $table->foreignId('nationality_id')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('mobile_phone', 30)->nullable();
            $table->string('home_phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index(['document_type_id', 'document_number'], 'persons_document_unique');
            $table->index('last_name', 'persons_last_name_idx');
            $table->index('email', 'persons_email_idx');
            $table->index('status_id', 'persons_status_id_idx');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `persons_document_unique` (`document_type_id`, `document_number`)' => 'UNIQUE KEY `persons_document_unique` (`document_type_id`, `document_number`)',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('persons');
    }

    public function version(): string
    {
        return '004_create_persons_table';
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
