<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Application\LockingUserCapabilityLookup;
use App\IdentityAccess\Domain\ValueObject\UserId;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final class PdoUserCapabilityLookup implements LockingUserCapabilityLookup
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function hasCapability(UserId $userId, string $capabilityCode): bool
    {
        return $this->hasCapabilityUsingLock($userId, $capabilityCode, false);
    }

    public function hasCapabilityForUpdate(UserId $userId, string $capabilityCode): bool
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('User capability row locking requires an active transaction.');
        }

        return $this->hasCapabilityUsingLock($userId, $capabilityCode, true);
    }

    private function hasCapabilityUsingLock(
        UserId $userId,
        string $capabilityCode,
        bool $forUpdate,
    ): bool
    {
        $sql = 'SELECT 1 FROM user_capabilities '
            . 'WHERE user_id = :userId AND capability_code = :capabilityCode LIMIT 1';
        if ($forUpdate && $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare(
            $sql
        );
        $statement->execute([
            ':userId' => $userId->value(),
            ':capabilityCode' => $capabilityCode,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
