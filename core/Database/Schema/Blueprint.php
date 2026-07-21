<?php

declare(strict_types=1);

namespace Core\Database\Schema;

final class Blueprint
{
    private string $table;

    private array $columns = [];

    private array $indexes = [];

    private array $foreignKeys = [];

    private ?Column $lastColumn = null;

    private ?int $lastForeignKeyIndex = null;

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

    public function getIndexes(): array
    {
        return $this->indexes;
    }

    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function id(): self
    {
        $this->addColumn(new Column('id', 'bigint', 20, false, null, false, true, true, true));

        return $this;
    }

    public function string(string $name, int $length = 255): self
    {
        $this->addColumn(new Column($name, 'varchar', $length));

        return $this;
    }

    public function bigInteger(string $name, ?int $length = null): self
    {
        $this->addColumn(new Column($name, 'bigint', $length));

        return $this;
    }

    public function unsignedBigInteger(string $name, ?int $length = null): self
    {
        $this->addColumn(new Column($name, 'bigint', $length, false, null, false, false, false, true));

        return $this;
    }

    public function integer(string $name, ?int $length = null): self
    {
        $this->addColumn(new Column($name, 'int', $length ?? 11));

        return $this;
    }

    public function unsignedInteger(string $name, ?int $length = null): self
    {
        $this->addColumn(new Column($name, 'int', $length ?? 11, false, null, false, false, false, true));

        return $this;
    }

    public function boolean(string $name): self
    {
        $this->addColumn(new Column($name, 'boolean'));

        return $this;
    }

    public function text(string $name): self
    {
        $this->addColumn(new Column($name, 'text'));

        return $this;
    }

    public function date(string $name): self
    {
        $this->addColumn(new Column($name, 'date'));

        return $this;
    }

    public function datetime(string $name): self
    {
        $this->addColumn(new Column($name, 'datetime'));

        return $this;
    }

    public function char(string $name, int $length): self
    {
        $this->addColumn(new Column($name, 'char', $length));

        return $this;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): self
    {
        $this->addColumn(new Column($name, 'decimal', null, false, null, false, false, false, false, $precision, $scale));

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
        $this->addColumn(new Column($name, 'bigint', 20, false, null, false, false, false, true));

        return $this;
    }

    public function index(string|array $columns = [], ?string $name = null): self
    {
        if ($columns === []) {
            if ($this->lastColumn === null) {
                return $this;
            }

            $columns = [$this->lastColumn->name()];
        }

        if (is_string($columns)) {
            $columns = [$columns];
        }

        $this->indexes[] = [
            'columns' => $columns,
            'name' => $name,
        ];

        return $this;
    }

    public function foreign(string $column): self
    {
        $this->foreignKeys[] = [
            'column' => $column,
            'references' => null,
            'on' => null,
            'onDelete' => null,
            'onUpdate' => null,
            'name' => null,
        ];

        $this->lastForeignKeyIndex = array_key_last($this->foreignKeys);

        return $this;
    }

    public function references(string $column): self
    {
        if ($this->lastForeignKeyIndex !== null) {
            $this->foreignKeys[$this->lastForeignKeyIndex]['references'] = $column;
        }

        return $this;
    }

    public function on(string $table): self
    {
        if ($this->lastForeignKeyIndex !== null) {
            $this->foreignKeys[$this->lastForeignKeyIndex]['on'] = $table;
        }

        return $this;
    }

    public function onDelete(string $action): self
    {
        if ($this->lastForeignKeyIndex !== null) {
            $this->foreignKeys[$this->lastForeignKeyIndex]['onDelete'] = strtoupper($action);
        }

        return $this;
    }

    public function onUpdate(string $action): self
    {
        if ($this->lastForeignKeyIndex !== null) {
            $this->foreignKeys[$this->lastForeignKeyIndex]['onUpdate'] = strtoupper($action);
        }

        return $this;
    }

    public function comment(string $text): self
    {
        if ($this->lastColumn !== null) {
            $this->lastColumn->setComment($text);
        }

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
