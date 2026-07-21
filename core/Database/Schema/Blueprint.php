<?php

declare(strict_types=1);

namespace Core\Database\Schema;

final class Blueprint
{
    private string $table;

    private array $columns = [];

    private ?Column $lastColumn = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function id(): self
    {
        $this->addColumn(new Column('id', 'bigint', 20, false, null, false, true, true));

        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->addColumn(new Column($name, 'varchar', $length));

        return $this;
    }

    public function integer(string $name, ?int $length = null): self
    {
        $this->addColumn(new Column($name, 'int', $length ?? 11));

        return $this;
    }

    public function boolean(string $name): self
    {
        $this->addColumn(new Column($name, 'tinyint', 1));

        return $this;
    }

    public function timestamp(string $name): self
    {
        $this->addColumn(new Column($name, 'timestamp'));

        return $this;
    }

    public function timestamps(): self
    {
        $this->timestamp('created_at');
        $this->timestamp('updated_at');

        return $this;
    }

    public function foreignId(string $name): self
    {
        $this->addColumn(new Column($name, 'bigint', 20));

        return $this;
    }

    public function nullable(): self
    {
        if ($this->lastColumn !== null) {
            $this->lastColumn->setNullable(true);
        }

        return $this;
    }

    public function unique(): self
    {
        if ($this->lastColumn !== null) {
            $this->lastColumn->setUnique(true);
        }

        return $this;
    }

    public function default(mixed $value): self
    {
        if ($this->lastColumn !== null) {
            $this->lastColumn->setDefault($value);
        }

        return $this;
    }

    private function addColumn(Column $column): void
    {
        $this->columns[] = $column;
        $this->lastColumn = $column;
    }
}
