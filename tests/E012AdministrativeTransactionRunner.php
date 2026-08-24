<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use RuntimeException;
use Throwable;

final class E012AdministrativeTransactionRunner implements TransactionRunner
{
    public int $calls = 0;
    public int $rollbacks = 0;
    public bool $active = false;
    public ?E012AdministrativeEnrollmentRepository $repository = null;

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new RuntimeException('Nested administrative transaction detected.');
        }

        $this->calls++;
        $this->active = true;
        if ($this->repository !== null) {
            $this->repository->trace[] = 'begin';
        }
        $snapshot = $this->repository?->snapshot() ?? [];
        try {
            $result = $operation();
            if ($this->repository !== null) {
                $this->repository->trace[] = 'commit';
            }
            $this->active = false;

            return $result;
        } catch (Throwable $exception) {
            $this->repository?->restore($snapshot);
            $this->rollbacks++;
            if ($this->repository !== null) {
                $this->repository->trace[] = 'rollback';
            }
            $this->active = false;

            throw $exception;
        }
    }
}
