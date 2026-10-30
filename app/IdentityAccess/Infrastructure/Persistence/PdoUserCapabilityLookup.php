<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Application\UserCapabilityLookup;
use App\IdentityAccess\Domain\ValueObject\UserId;
use Core\Database\ConnectionManager;
use PDO;

final class PdoUserCapabilityLookup implements UserCapabilityLookup
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function hasCapability(UserId $userId, string $capabilityCode): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM user_capabilities '
            . 'WHERE user_id = :userId AND capability_code = :capabilityCode LIMIT 1'
        );
        $statement->execute([
            ':userId' => $userId->value(),
            ':capabilityCode' => $capabilityCode,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
