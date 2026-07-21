<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class NationalitySeeder
{
    public function run(PDO $connection): void
    {
        $rows = [
            ['name' => 'Venezuelan', 'description' => 'Venezuelan nationality'],
            ['name' => 'Colombian', 'description' => 'Colombian nationality'],
            ['name' => 'Other', 'description' => 'Other nationality'],
        ];

        $exists = $connection->prepare('SELECT id FROM nationalities WHERE name = :name LIMIT 1');
        $insert = $connection->prepare(
            'INSERT INTO nationalities (name, description, created_at, updated_at) '
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
