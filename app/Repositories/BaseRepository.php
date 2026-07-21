<?php

declare(strict_types=1);

namespace App\Repositories;

use Core\Database\ConnectionManager;
use PDO;

abstract class BaseRepository
{
    protected PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function execute(string $sql, array $params = []): bool
    {
        $statement = $this->connection->prepare($sql);

        return $statement->execute($params);
    }

    protected function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }
}
