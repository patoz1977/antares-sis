<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Application\TransactionRunner;
use PDO;
use RuntimeException;
use Throwable;

final class PdoTransactionRunner implements TransactionRunner
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function run(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Transactional operation cannot start inside an active transaction.');
        }

        if (!$this->connection->beginTransaction()) {
            throw new RuntimeException('Transactional operation could not start its transaction.');
        }

        try {
            $result = $operation();

            if (!$this->connection->commit()) {
                throw new RuntimeException('Transactional operation could not commit its transaction.');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction() && !$this->connection->rollBack()) {
                throw new RuntimeException(
                    'Transactional operation could not roll back its transaction.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
