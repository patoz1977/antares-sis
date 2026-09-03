<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Core\Application\TransactionRunner;

final class MariaDbBeforeTransactionRunner implements TransactionRunner
{
    private int $calls = 0;
    private bool $injected = false;

    public function __construct(
        private readonly TransactionRunner $delegate,
        private readonly Closure $beforeFirstTransaction,
    ) {
    }

    public function run(callable $operation): mixed
    {
        ++$this->calls;
        if (!$this->injected) {
            $this->injected = true;
            ($this->beforeFirstTransaction)();
        }

        return $this->delegate->run($operation);
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
