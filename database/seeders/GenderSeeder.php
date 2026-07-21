<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class GenderSeeder
{
    public function run(PDO $connection): void
    {
        $rows = [
            ['name' => 'Female', 'description' => 'Female gender'],
            ['name' => 'Male', 'description' => 'Male gender'],
            ['name' => 'Other', 'description' => 'Other gender identity'],
        ];

        $exists = $connection->prepare('SELECT id FROM genders WHERE name = :name LIMIT 1');
        $insert = $connection->prepare(
            'INSERT INTO genders (name, description, created_at, updated_at) '
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
