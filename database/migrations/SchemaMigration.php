<?php

declare(strict_types=1);

use Core\Database\Migration;

abstract class SchemaMigration extends Migration
{
    /** @param list<string> $statements */
    final protected function createTables(PDO $connection, array $statements): void
    {
        foreach ($statements as $statement) {
            $connection->exec($statement);
        }
    }

    /** @param list<string> $tables */
    final protected function dropTables(PDO $connection, array $tables): void
    {
        foreach ($tables as $table) {
            $connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
