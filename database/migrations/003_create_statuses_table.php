<?php

declare(strict_types=1);

use Core\Database\Migration;
use Core\Database\Schema\Blueprint;
use Core\Database\Schema\Schema;

final class CreateStatusesTable extends Migration
{
    public function up(PDO $connection): void
    {
        $this->createTable($connection, 'statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_type_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('display_order', 5);
            $table->string('color', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->index(['status_type_id', 'code'], 'statuses_type_code_unique');
            $table->index('status_type_id', 'statuses_status_type_id_idx');
            $table->index('display_order', 'statuses_display_order_idx');
        }, [
            "DEFAULT 'CURRENT_TIMESTAMP'" => 'DEFAULT CURRENT_TIMESTAMP',
            'KEY `statuses_type_code_unique` (`status_type_id`, `code`)' => 'UNIQUE KEY `statuses_type_code_unique` (`status_type_id`, `code`)',
            '`display_order` INT(5) UNSIGNED NOT NULL' => '`display_order` SMALLINT UNSIGNED NOT NULL',
        ]);
    }

    public function down(PDO $connection): void
    {
        (new Schema())->drop('statuses');
    }

    public function version(): string
    {
        return '003_create_statuses_table';
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
