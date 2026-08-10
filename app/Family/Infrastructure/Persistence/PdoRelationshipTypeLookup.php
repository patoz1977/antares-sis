<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Application\RelationshipTypeLookup;
use Core\Database\ConnectionManager;
use PDO;

final class PdoRelationshipTypeLookup implements RelationshipTypeLookup
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function exists(int $relationshipTypeId): bool
    {
        if ($relationshipTypeId <= 0) {
            return false;
        }

        $statement = $this->connection->prepare(
            'SELECT 1 FROM relationship_types '
            . 'WHERE id = :relationshipTypeId AND is_active = TRUE LIMIT 1'
        );
        $statement->execute([':relationshipTypeId' => $relationshipTypeId]);

        return $statement->fetchColumn() !== false;
    }
}
