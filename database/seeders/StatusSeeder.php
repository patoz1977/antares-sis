<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class StatusSeeder
{
    public function run(PDO $connection): void
    {
        $types = $connection->prepare(
            'SELECT id, code FROM status_types '
            . 'WHERE code IN (:userCode, :generalCode, :enrollmentCode)'
        );
        $types->execute([
            ':userCode' => 'USER_STATUS',
            ':generalCode' => 'GENERAL_STATUS',
            ':enrollmentCode' => 'ENROLLMENT_STATUS',
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
            . '(status_type_id, code, name, description, sort_order, is_active, created_at, updated_at) '
            . 'VALUES (:statusTypeId, :code, :name, :description, :sortOrder, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'name = VALUES(name), '
            . 'description = VALUES(description), '
            . 'sort_order = VALUES(sort_order), '
            . 'is_active = VALUES(is_active), '
            . 'updated_at = CURRENT_TIMESTAMP'
        );

        $rows = [
            [
                'typeCode' => 'USER_STATUS',
                'code' => 'ACTIVE',
                'name' => 'Active',
                'description' => 'Cuenta activa',
                'sortOrder' => 1,
            ],
            [
                'typeCode' => 'USER_STATUS',
                'code' => 'DISABLED',
                'name' => 'Disabled',
                'description' => 'Cuenta deshabilitada',
                'sortOrder' => 2,
            ],
            [
                'typeCode' => 'GENERAL_STATUS',
                'code' => 'ACTIVE',
                'name' => 'Active',
                'description' => 'Registro activo',
                'sortOrder' => 1,
            ],
            [
                'typeCode' => 'GENERAL_STATUS',
                'code' => 'INACTIVE',
                'name' => 'Inactive',
                'description' => 'Registro inactivo',
                'sortOrder' => 2,
            ],
            [
                'typeCode' => 'ENROLLMENT_STATUS',
                'code' => 'DRAFT',
                'name' => 'Draft',
                'description' => 'Matrícula en borrador',
                'sortOrder' => 1,
            ],
            [
                'typeCode' => 'ENROLLMENT_STATUS',
                'code' => 'SUBMITTED',
                'name' => 'Submitted',
                'description' => 'Matrícula enviada',
                'sortOrder' => 2,
            ],
            [
                'typeCode' => 'ENROLLMENT_STATUS',
                'code' => 'COMPLETED',
                'name' => 'Completed',
                'description' => 'Matrícula completada',
                'sortOrder' => 3,
            ],
            [
                'typeCode' => 'ENROLLMENT_STATUS',
                'code' => 'CANCELLED',
                'name' => 'Cancelled',
                'description' => 'Matrícula cancelada',
                'sortOrder' => 4,
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
                ':sortOrder' => $row['sortOrder'],
            ]);
        }
    }
}
