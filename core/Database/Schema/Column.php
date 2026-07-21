<?php

declare(strict_types=1);

namespace Core\Database\Schema;

final class Column
{
    private string $name;

    private string $type;

    private ?int $length;

    private bool $nullable;

    private mixed $default;

    private bool $unique;

    private bool $autoIncrement;

    private bool $primary;

    public function __construct(
        string $name,
        string $type,
        ?int $length = null,
        bool $nullable = false,
        mixed $default = null,
        bool $unique = false,
        bool $autoIncrement = false,
        bool $primary = false
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->length = $length;
        $this->nullable = $nullable;
        $this->default = $default;
        $this->unique = $unique;
        $this->autoIncrement = $autoIncrement;
        $this->primary = $primary;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function length(): ?int
    {
        return $this->length;
    }

    public function nullable(): bool
    {
        return $this->nullable;
    }

    public function default(): mixed
    {
        return $this->default;
    }

    public function unique(): bool
    {
        return $this->unique;
    }

    public function autoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function primary(): bool
    {
        return $this->primary;
    }

    public function setNullable(bool $nullable): void
    {
        $this->nullable = $nullable;
    }

    public function setDefault(mixed $default): void
    {
        $this->default = $default;
    }

    public function setUnique(bool $unique): void
    {
        $this->unique = $unique;
    }
}
