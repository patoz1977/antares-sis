<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class InMemoryCompositeTransactionRunner implements TransactionRunner
{
    private bool $active = false;

    private int $beginCount = 0;

    private int $commitCount = 0;

    private int $rollbackCount = 0;

    /** @param list<object> $participants */
    public function __construct(private readonly array $participants)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->active) {
            throw new RuntimeException('In-memory transaction is already active.');
        }

        $snapshots = array_map($this->snapshot(...), $this->participants);
        $this->active = true;
        $this->beginCount++;

        try {
            $result = $operation();
            $this->commitCount++;

            return $result;
        } catch (Throwable $exception) {
            foreach ($this->participants as $index => $participant) {
                $this->restore($participant, $snapshots[$index]);
            }
            $this->rollbackCount++;

            throw $exception;
        } finally {
            $this->active = false;
        }
    }

    public function beginCount(): int
    {
        return $this->beginCount;
    }

    public function commitCount(): int
    {
        return $this->commitCount;
    }

    public function rollbackCount(): int
    {
        return $this->rollbackCount;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    private function snapshot(object $participant): object
    {
        $serialized = serialize($participant);
        $snapshot = unserialize($serialized, ['allowed_classes' => true]);
        if (!is_object($snapshot)) {
            throw new RuntimeException('Unable to snapshot in-memory transaction participant.');
        }

        return $snapshot;
    }

    private function restore(object $participant, object $snapshot): void
    {
        $reflection = new ReflectionClass($participant);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $property->setValue($participant, $property->getValue($snapshot));
        }
    }
}
