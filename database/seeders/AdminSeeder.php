<?php

declare(strict_types=1);

namespace Database\Seeders;

use PDO;

final class AdminSeeder
{
    private const REPORTS_VIEW_ALL = 'REPORTS_VIEW_ALL';

    public function run(PDO $connection): void
    {
        $administratorId = $this->administratorId($connection);
        if ($administratorId !== null) {
            $this->ensureReportsCapability($connection, $administratorId);

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

            $administratorId = (int) $connection->lastInsertId();
            if ($administratorId <= 0) {
                throw new \RuntimeException('Administrator insert did not produce a valid identity.');
            }
            $this->ensureReportsCapability($connection, $administratorId);

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

    private function administratorId(PDO $connection): ?int
    {
        $statement = $connection->prepare(
            'SELECT id, login_identifier, normalized_login_identifier FROM users '
            . 'WHERE login_identifier = :loginIdentifier '
            . 'OR normalized_login_identifier = :normalizedLoginIdentifier '
            . 'ORDER BY id LIMIT 2'
        );
        $statement->execute([
            ':loginIdentifier' => 'admin',
            ':normalizedLoginIdentifier' => 'admin',
        ]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) !== 1
            || $candidates[0]['login_identifier'] !== 'admin'
            || $candidates[0]['normalized_login_identifier'] !== 'admin') {
            throw new \RuntimeException('Administrator identity is incoherent.');
        }

        $id = (int) $candidates[0]['id'];
        if ($id <= 0) {
            throw new \RuntimeException('Administrator identity is invalid.');
        }

        return $id;
    }

    private function ensureReportsCapability(PDO $connection, int $administratorId): void
    {
        $statement = $connection->prepare(
            'INSERT INTO user_capabilities (user_id, capability_code) '
            . 'SELECT :userId, :capabilityCode WHERE NOT EXISTS ('
            . 'SELECT 1 FROM user_capabilities '
            . 'WHERE user_id = :existingUserId AND capability_code = :existingCapabilityCode'
            . ')'
        );
        $statement->execute([
            ':userId' => $administratorId,
            ':capabilityCode' => self::REPORTS_VIEW_ALL,
            ':existingUserId' => $administratorId,
            ':existingCapabilityCode' => self::REPORTS_VIEW_ALL,
        ]);
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
