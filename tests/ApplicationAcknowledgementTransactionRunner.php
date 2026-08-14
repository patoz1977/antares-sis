<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use Throwable;

final class ApplicationAcknowledgementTransactionRunner implements TransactionRunner
{
    public int $runCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    public function __construct(private readonly ApplicationCompletionRepository $completions)
    {
    }

    public function run(callable $operation): mixed
    {
        $this->runCount++;
        $snapshot = $this->completions->snapshot();
        try {
            $result = $operation();
            $this->commitCount++;

            return $result;
        } catch (Throwable $exception) {
            $this->completions->restore($snapshot);
            $this->rollbackCount++;
            throw $exception;
        }
    }
}
