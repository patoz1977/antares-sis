<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Application\DocumentTypeLookup;
use Core\Database\ConnectionManager;
use PDO;

final class PdoDocumentTypeLookup implements DocumentTypeLookup
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function exists(int $documentTypeId): bool
    {
        if ($documentTypeId <= 0) {
            return false;
        }

        $statement = $this->connection->prepare(
            'SELECT 1 FROM document_types '
            . 'WHERE id = :documentTypeId AND is_active = TRUE LIMIT 1'
        );
        $statement->execute([':documentTypeId' => $documentTypeId]);

        return $statement->fetchColumn() !== false;
    }
}
