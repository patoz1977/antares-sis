<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PdoUserRepository implements UserRepository
{
    private const STATUS_TYPE = 'USER_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
    {
        return $this->findByLoginIdentifierUsingLock($identifier, false);
    }

    public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('User row locking requires an active transaction.');
        }

        return $this->findByLoginIdentifierUsingLock($identifier, true);
    }

    private function findByLoginIdentifierUsingLock(
        LoginIdentifier $identifier,
        bool $forUpdate
    ): ?User
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE u.normalized_login_identifier = :identifier'
            . ' LIMIT 1'
            . ($forUpdate && $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' FOR UPDATE'
                : '')
        );
        $statement->execute([':identifier' => $identifier->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findById(UserId $id): ?User
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE u.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findByPersonId(PersonId $personId): ?User
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE u.person_id = :personId LIMIT 1'
        );
        $statement->execute([':personId' => $personId->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function save(User $user): User
    {
        $statusId = $this->resolveStatusId($user->status());

        if ($user->id() === null) {
            return $this->insert($user, $statusId);
        }

        return $this->update($user, $statusId);
    }

    private function insert(User $user, int $statusId): User
    {
        $statement = $this->connection->prepare(
            'INSERT INTO users ('
            . 'person_id, login_identifier, normalized_login_identifier, password_hash, '
            . 'status_id, failed_login_attempts, locked_at, last_access_at'
            . ') VALUES ('
            . ':personId, :loginIdentifier, :normalizedLoginIdentifier, :passwordHash, '
            . ':statusId, :failedLoginAttempts, :lockedAt, :lastAccessAt'
            . ')'
        );
        $statement->execute($this->persistenceValues($user, $statusId));

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('User insert did not affect exactly one row.');
        }

        $generatedId = (int) $this->connection->lastInsertId();
        if ($generatedId <= 0) {
            throw new RuntimeException('User insert did not produce a positive database identity.');
        }

        $persisted = $this->findById(new UserId($generatedId));
        if ($persisted === null) {
            throw new RuntimeException('Inserted User could not be reconstructed.');
        }

        return $persisted;
    }

    private function update(User $user, int $statusId): User
    {
        $id = $user->id();
        if ($id === null) {
            throw new RuntimeException('A User without persisted identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE users SET '
            . 'login_identifier = :loginIdentifier, '
            . 'normalized_login_identifier = :normalizedLoginIdentifier, '
            . 'password_hash = :passwordHash, status_id = :statusId, '
            . 'failed_login_attempts = :failedLoginAttempts, locked_at = :lockedAt, '
            . 'last_access_at = :lastAccessAt, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE id = :id'
        );
        $values = $this->persistenceValues($user, $statusId);
        unset($values[':personId']);
        $values[':id'] = $id->value();
        $statement->execute($values);

        $affectedRows = $statement->rowCount();
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException('User update did not affect zero or one row.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('User update failed because the persisted row disappeared.');
        }

        if ($affectedRows === 0 && !$this->sameState($persisted, $user)) {
            throw new RuntimeException('User update did not persist the requested state.');
        }

        return $persisted;
    }

    private function resolveStatusId(UserStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => $status->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($rows) !== 1) {
            throw new RuntimeException('User status must resolve to exactly one USER_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, int|string|null> */
    private function persistenceValues(User $user, int $statusId): array
    {
        $identifier = $user->loginIdentifier()->value();

        return [
            ':personId' => $user->personId()->value(),
            ':loginIdentifier' => $identifier,
            ':normalizedLoginIdentifier' => $identifier,
            ':passwordHash' => $user->passwordHash()->value(),
            ':statusId' => $statusId,
            ':failedLoginAttempts' => $user->failedLoginAttempts(),
            ':lockedAt' => $this->formatDate($user->lockedAt()),
            ':lastAccessAt' => $this->formatDate($user->lastAccessAt()),
        ];
    }

    private function selectSql(): string
    {
        return 'SELECT u.id, u.person_id, u.login_identifier, u.normalized_login_identifier, '
            . 'u.password_hash, u.failed_login_attempts, u.locked_at, '
            . 'u.last_access_at, s.code AS status_code, st.code AS status_type_code '
            . 'FROM users u '
            . 'INNER JOIN persons p ON p.id = u.person_id '
            . 'INNER JOIN statuses s ON s.id = u.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    private function mapRow(array|false $row): ?User
    {
        if ($row === false) {
            return null;
        }

        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('User status does not belong to USER_STATUS.');
        }

        $status = UserStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('User has an unsupported USER_STATUS value.');
        }

        $identifier = new LoginIdentifier((string) $row['login_identifier']);
        if ($identifier->value() !== (string) $row['normalized_login_identifier']) {
            throw new RuntimeException('User has an inconsistent normalized login identifier.');
        }

        return new User(
            new UserId((int) $row['id']),
            new PersonId((int) $row['person_id']),
            $identifier,
            new PasswordHash((string) $row['password_hash']),
            $status,
            (int) $row['failed_login_attempts'],
            $this->parseDate($row['locked_at']),
            $this->parseDate($row['last_access_at']),
        );
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC'),
        );
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('User has an invalid persisted UTC timestamp.');
        }

        return $date;
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function sameState(User $persisted, User $user): bool
    {
        $persistedId = $persisted->id();
        $userId = $user->id();
        if ($persistedId === null || $userId === null || $persistedId->value() !== $userId->value()) {
            return false;
        }

        return $persisted->personId()->value() === $user->personId()->value()
            && $persisted->loginIdentifier()->value() === $user->loginIdentifier()->value()
            && $persisted->passwordHash()->value() === $user->passwordHash()->value()
            && $persisted->status() === $user->status()
            && $persisted->failedLoginAttempts() === $user->failedLoginAttempts()
            && $this->sameDate($persisted->lockedAt(), $user->lockedAt())
            && $this->sameDate($persisted->lastAccessAt(), $user->lastAccessAt());
    }

    private function sameDate(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        return $left === null
            ? $right === null
            : $right !== null && $left->getTimestamp() === $right->getTimestamp();
    }
}
