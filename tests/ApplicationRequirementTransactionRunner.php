<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use Throwable;

final class ApplicationRequirementTransactionRunner implements TransactionRunner
{
    public int $runCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    public function __construct(private readonly ApplicationRequirementRepository $requirements)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->runCount++;
        $snapshot = $this->requirements->snapshot();

        try {
            $result = $operation();
            $this->commitCount++;

            return $result;
        } catch (Throwable $exception) {
            $this->requirements->restore($snapshot);
            $this->rollbackCount++;

            throw $exception;
        }
    }
}
