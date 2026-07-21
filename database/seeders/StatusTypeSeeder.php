<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class StatusTypeSeeder
{
    public function run(PDO $connection): void
    {
        $connection->exec(
            'INSERT INTO status_types (code, name, description, created_at, updated_at) VALUES '
            . "('USER_STATUS', 'User Status', 'Estados de cuentas de usuario', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "('PERSON_STATUS', 'Person Status', 'Estados de personas', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), updated_at = CURRENT_TIMESTAMP'
        );
    }
}
