<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class AdminSeeder
{
    public function run(PDO $connection): void
    {
        $statement = $connection->prepare(
            'SELECT id FROM statuses WHERE status_type_id = :statusTypeId AND code = :code LIMIT 1'
        );
        $statement->execute([':statusTypeId' => 1, ':code' => 'ACTIVE']);
        $status = $statement->fetch(PDO::FETCH_ASSOC);

        if ($status === false) {
            return;
        }

        $connection->exec(
            'INSERT INTO persons (status_id, document_type_id, document_number, first_name, last_name, email, created_at, updated_at) VALUES '
            . "(2, 1, '0000000000', 'Administrator', 'System', 'admin@example.com', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            . ' ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), email = VALUES(email)'
        );

        $connection->exec(
            'INSERT INTO users (person_id, status_id, username, email, password_hash, created_at, updated_at) VALUES '
            . "(1, {$status['id']}, 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            . ' ON DUPLICATE KEY UPDATE username = VALUES(username), password_hash = VALUES(password_hash)'
        );
    }
}
