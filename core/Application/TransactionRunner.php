<?php

declare(strict_types=1);

namespace Core\Application;

interface TransactionRunner
{
    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    public function run(callable $operation): mixed;
}
