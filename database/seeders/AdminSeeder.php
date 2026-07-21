<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class AdminSeeder
{
    public function run(PDO $connection): void
    {
        $userStatusId = $this->findStatusId($connection, 'USER_STATUS', 'ACTIVE');
        $personStatusId = $this->findStatusId($connection, 'PERSON_STATUS', 'ACTIVE');

        if ($userStatusId === null || $personStatusId === null) {
            return;
        }

        $documentTypeId = $this->resolveDocumentTypeId($connection);

        $passwordHash = password_hash('Admin123!', PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return;
        }

        $connection->beginTransaction();

        try {
            $personUpsert = $connection->prepare(
                'INSERT INTO persons '
                . '(status_id, document_type_id, document_number, first_name, last_name, email, created_at, updated_at) '
                . 'VALUES (:statusId, :documentTypeId, :documentNumber, :firstName, :lastName, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'status_id = VALUES(status_id), '
                . 'first_name = VALUES(first_name), '
                . 'last_name = VALUES(last_name), '
                . 'email = VALUES(email), '
                . 'updated_at = CURRENT_TIMESTAMP'
            );

            $personUpsert->execute([
                ':statusId' => $personStatusId,
                ':documentTypeId' => $documentTypeId,
                ':documentNumber' => 'ADMIN-0001',
                ':firstName' => 'Administrator',
                ':lastName' => 'System',
                ':email' => 'admin@example.com',
            ]);

            $personIdQuery = $connection->prepare(
                'SELECT id FROM persons WHERE document_type_id = :documentTypeId AND document_number = :documentNumber LIMIT 1'
            );
            $personIdQuery->execute([
                ':documentTypeId' => $documentTypeId,
                ':documentNumber' => 'ADMIN-0001',
            ]);

            $person = $personIdQuery->fetch(PDO::FETCH_ASSOC);
            if ($person === false) {
                $connection->rollBack();
                return;
            }

            $userUpsert = $connection->prepare(
                'INSERT INTO users '
                . '(person_id, status_id, username, email, password_hash, created_at, updated_at) '
                . 'VALUES (:personId, :statusId, :username, :email, :passwordHash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'person_id = VALUES(person_id), '
                . 'status_id = VALUES(status_id), '
                . 'email = VALUES(email), '
                . 'password_hash = VALUES(password_hash), '
                . 'updated_at = CURRENT_TIMESTAMP'
            );

            $userUpsert->execute([
                ':personId' => (int) $person['id'],
                ':statusId' => $userStatusId,
                ':username' => 'admin',
                ':email' => 'admin@example.com',
                ':passwordHash' => $passwordHash,
            ]);

            $connection->commit();
        } catch (\Throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new \RuntimeException('Unable to seed administrator user.');
        }
    }

    private function findStatusId(PDO $connection, string $statusTypeCode, string $statusCode): ?int
    {
        $statement = $connection->prepare(
            'SELECT s.id '
            . 'FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusTypeCode AND s.code = :statusCode '
            . 'LIMIT 1'
        );

        $statement->execute([
            ':statusTypeCode' => $statusTypeCode,
            ':statusCode' => $statusCode,
        ]);

        $status = $statement->fetch(PDO::FETCH_ASSOC);

        if ($status === false) {
            return null;
        }

        return (int) $status['id'];
    }

    private function resolveDocumentTypeId(PDO $connection): int
    {
        try {
            $statement = $connection->query('SELECT id FROM document_types ORDER BY id ASC LIMIT 1');
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row !== false && isset($row['id'])) {
                return (int) $row['id'];
            }
        } catch (\Throwable) {
            return 1;
        }

        return 1;
    }
}
