<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class StatusSeeder
{
    public function run(PDO $connection): void
    {
        $connection->exec(
            'INSERT INTO statuses (status_type_id, code, name, description, display_order, color, is_default, is_terminal, created_at, updated_at) VALUES '
            . "(1, 'ACTIVE', 'Active', 'Cuenta activa', 1, '#28a745', TRUE, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "(1, 'INACTIVE', 'Inactive', 'Cuenta inactiva', 2, '#6c757d', FALSE, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "(1, 'SUSPENDED', 'Suspended', 'Cuenta suspendida', 3, '#dc3545', FALSE, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "(2, 'ACTIVE', 'Active', 'Persona activa', 1, '#28a745', TRUE, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),"
            . "(2, 'INACTIVE', 'Inactive', 'Persona inactiva', 2, '#6c757d', FALSE, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), display_order = VALUES(display_order), color = VALUES(color), is_default = VALUES(is_default), is_terminal = VALUES(is_terminal)'
        );
    }
}
