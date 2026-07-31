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
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE u.normalized_login_identifier = :identifier'
            . ' AND u.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute([':identifier' => $identifier->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findById(UserId $id): ?User
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function save(User $user): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET failed_login_attempts = :failedLoginAttempts, '
            . 'locked_at = :lockedAt, last_access_at = :lastAccessAt, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            ':failedLoginAttempts' => $user->failedLoginAttempts(),
            ':lockedAt' => $this->formatDate($user->lockedAt()),
            ':lastAccessAt' => $this->formatDate($user->lastAccessAt()),
            ':id' => $user->id()->value(),
        ]);

        if ($statement->rowCount() > 1) {
            throw new RuntimeException('Unexpected number of updated User rows.');
        }
    }

    private function selectSql(): string
    {
        return 'SELECT u.id, u.person_id, u.normalized_login_identifier, '
            . 'u.password_hash, u.failed_login_attempts, u.locked_at, '
            . 'u.last_access_at, s.code AS status_code '
            . 'FROM users u '
            . 'INNER JOIN persons p ON p.id = u.person_id '
            . 'INNER JOIN statuses s ON s.id = u.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . "AND st.code = 'USER_STATUS'";
    }

    private function mapRow(array|false $row): ?User
    {
        if ($row === false) {
            return null;
        }

        $status = UserStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('User has an unsupported USER_STATUS value.');
        }

        return new User(
            new UserId((int) $row['id']),
            new PersonId((int) $row['person_id']),
            new LoginIdentifier((string) $row['normalized_login_identifier']),
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

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
