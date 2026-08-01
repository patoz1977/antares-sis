<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class StatusTypeSeeder
{
    public function run(PDO $connection): void
    {
        $connection->exec(
            'INSERT INTO status_types (code, name, description, is_active, created_at, updated_at) VALUES '
            . "('USER_STATUS', 'User Status', 'Estados de cuentas de usuario', TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "('GENERAL_STATUS', 'General Status', 'Estados generales del dominio', TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "('ENROLLMENT_STATUS', 'Enrollment Status', 'Estados del ciclo de matrícula', TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), '
            . 'is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP'
        );
    }
}
