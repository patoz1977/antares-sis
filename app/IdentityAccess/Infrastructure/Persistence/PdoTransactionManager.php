<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Application\Contract\TransactionManager;
use Core\Database\ConnectionManager;
use PDO;
use Throwable;

final class PdoTransactionManager implements TransactionManager
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function transactional(callable $operation): mixed
    {
        $startedTransaction = !$this->connection->inTransaction();

        if ($startedTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $operation();

            if ($startedTransaction && $this->connection->inTransaction()) {
                $this->connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
