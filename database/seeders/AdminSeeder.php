<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class AdminSeeder
{
    public function run(PDO $connection): void
    {
        if ($this->administratorExists($connection)) {
            return;
        }

        $personIdValue = getenv('E0041_ADMIN_PERSON_ID');
        $initialPassword = getenv('E0041_ADMIN_INITIAL_PASSWORD');

        if ((!is_string($personIdValue) || $personIdValue === '')
            && (!is_string($initialPassword) || $initialPassword === '')) {
            return;
        }

        if (!is_string($personIdValue) || filter_var($personIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \RuntimeException('E0041_ADMIN_PERSON_ID must reference an existing Person.');
        }

        if (!is_string($initialPassword) || $initialPassword === '') {
            throw new \RuntimeException('E0041_ADMIN_INITIAL_PASSWORD is required to create the administrator user.');
        }

        $personId = (int) $personIdValue;
        if (!$this->personExists($connection, $personId)) {
            throw new \RuntimeException('E0041_ADMIN_PERSON_ID does not reference an existing Person.');
        }

        $userStatusId = $this->findStatusId($connection, 'USER_STATUS', 'ACTIVE');
        if ($userStatusId === null) {
            throw new \RuntimeException('The ACTIVE USER_STATUS must exist before creating the administrator user.');
        }

        $connection->beginTransaction();

        try {
            $existingUserQuery = $connection->prepare(
                'SELECT id FROM users '
                . 'WHERE person_id = :personId OR normalized_login_identifier = :loginIdentifier LIMIT 1'
            );
            $existingUserQuery->execute([
                ':personId' => $personId,
                ':loginIdentifier' => 'admin',
            ]);

            if ($existingUserQuery->fetch(PDO::FETCH_ASSOC) !== false) {
                $connection->commit();

                return;
            }

            $passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new \RuntimeException('Unable to hash the initial administrator password.');
            }

            $userInsert = $connection->prepare(
                'INSERT INTO users '
                . '(person_id, status_id, login_identifier, normalized_login_identifier, password_hash, created_at, updated_at) '
                . 'VALUES (:personId, :statusId, :loginIdentifier, :normalizedLoginIdentifier, :passwordHash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );

            $userInsert->execute([
                ':personId' => $personId,
                ':statusId' => $userStatusId,
                ':loginIdentifier' => 'admin',
                ':normalizedLoginIdentifier' => 'admin',
                ':passwordHash' => $passwordHash,
            ]);

            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new \RuntimeException('Unable to seed administrator user.', previous: $exception);
        }
    }

    private function personExists(PDO $connection, int $personId): bool
    {
        $statement = $connection->prepare('SELECT id FROM persons WHERE id = :personId LIMIT 1');
        $statement->execute([':personId' => $personId]);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function administratorExists(PDO $connection): bool
    {
        $statement = $connection->prepare(
            'SELECT id FROM users WHERE normalized_login_identifier = :loginIdentifier LIMIT 1'
        );
        $statement->execute([':loginIdentifier' => 'admin']);

        return $statement->fetch(PDO::FETCH_ASSOC) !== false;
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

}
