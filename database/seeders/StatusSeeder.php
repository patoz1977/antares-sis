<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class StatusSeeder
{
    public function run(PDO $connection): void
    {
        $types = $connection->prepare('SELECT id, code FROM status_types WHERE code IN (:userCode, :personCode)');
        $types->execute([
            ':userCode' => 'USER_STATUS',
            ':personCode' => 'PERSON_STATUS',
        ]);

        $typeRows = $types->fetchAll(PDO::FETCH_ASSOC);
        if ($typeRows === []) {
            return;
        }

        $typeIdsByCode = [];
        foreach ($typeRows as $row) {
            $typeIdsByCode[(string) $row['code']] = (int) $row['id'];
        }

        $insert = $connection->prepare(
            'INSERT INTO statuses '
            . '(status_type_id, code, name, description, display_order, color, is_default, is_terminal, created_at, updated_at) '
            . 'VALUES (:statusTypeId, :code, :name, :description, :displayOrder, :color, :isDefault, :isTerminal, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'name = VALUES(name), '
            . 'description = VALUES(description), '
            . 'display_order = VALUES(display_order), '
            . 'color = VALUES(color), '
            . 'is_default = VALUES(is_default), '
            . 'is_terminal = VALUES(is_terminal), '
            . 'updated_at = CURRENT_TIMESTAMP'
        );

        $rows = [
            [
                'typeCode' => 'USER_STATUS',
                'code' => 'ACTIVE',
                'name' => 'Active',
                'description' => 'Cuenta activa',
                'displayOrder' => 1,
                'color' => '#28a745',
                'isDefault' => 1,
                'isTerminal' => 0,
            ],
            [
                'typeCode' => 'PERSON_STATUS',
                'code' => 'ACTIVE',
                'name' => 'Active',
                'description' => 'Persona activa',
                'displayOrder' => 1,
                'color' => '#28a745',
                'isDefault' => 1,
                'isTerminal' => 0,
            ],
        ];

        foreach ($rows as $row) {
            if (!isset($typeIdsByCode[$row['typeCode']])) {
                continue;
            }

            $insert->execute([
                ':statusTypeId' => $typeIdsByCode[$row['typeCode']],
                ':code' => $row['code'],
                ':name' => $row['name'],
                ':description' => $row['description'],
                ':displayOrder' => $row['displayOrder'],
                ':color' => $row['color'],
                ':isDefault' => $row['isDefault'],
                ':isTerminal' => $row['isTerminal'],
            ]);
        }
    }
}
