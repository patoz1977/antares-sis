<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class DocumentTypeSeeder
{
    public function run(PDO $connection): void
    {
        $rows = [
            ['name' => 'National ID', 'description' => 'National identification document'],
            ['name' => 'Passport', 'description' => 'Passport document'],
            ['name' => 'Birth Certificate', 'description' => 'Birth certificate document'],
        ];

        $exists = $connection->prepare('SELECT id FROM document_types WHERE name = :name LIMIT 1');
        $insert = $connection->prepare(
            'INSERT INTO document_types (name, description, created_at, updated_at) '
            . 'VALUES (:name, :description, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );

        foreach ($rows as $row) {
            $exists->execute([':name' => $row['name']]);
            $record = $exists->fetch(PDO::FETCH_ASSOC);

            if ($record !== false) {
                continue;
            }

            $insert->execute([
                ':name' => $row['name'],
                ':description' => $row['description'],
            ]);
        }
    }
}
