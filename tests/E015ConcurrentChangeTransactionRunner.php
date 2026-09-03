<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use PDOException;

final class E015ConcurrentChangeTransactionRunner implements TransactionRunner
{
    private int $calls = 0;

    public function run(callable $operation): mixed
    {
        ++$this->calls;
        $exception = new PDOException('Synthetic deadlock detail must remain private.', 40001);
        $exception->errorInfo = ['40001', 1213, 'Synthetic deadlock detail must remain private.'];

        throw $exception;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
