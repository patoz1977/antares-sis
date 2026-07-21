<?php

declare(strict_types=1);

namespace Core\Database\Schema;

use Core\Database\ConnectionManager;

final class Schema
{
    private ?ConnectionManager $connectionManager = null;

    public function __construct(?ConnectionManager $connectionManager = null)
    {
        $this->connectionManager = $connectionManager;
    }

    public function create(string $table, callable $callback): void
    {
        $this->builder()->create($table, $callback);
    }

    public function drop(string $table): void
    {
        $this->builder()->drop($table);
    }

    private function builder(): SchemaBuilder
    {
        return new SchemaBuilder($this->connectionManager);
    }
}
