<?php

declare(strict_types=1);

namespace Core\Database\Schema;

use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use PDO;

final class SchemaBuilder
{
    private ?ConnectionManager $connectionManager = null;

    public function __construct(?ConnectionManager $connectionManager = null)
    {
        $this->connectionManager = $connectionManager;
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $sql = $this->compileCreateTable($blueprint);
        $this->connection()->exec($sql);
    }

    public function drop(string $table): void
    {
        $sql = sprintf('DROP TABLE IF EXISTS %s', $this->quoteIdentifier($table));
        $this->connection()->exec($sql);
    }

    private function connection(): PDO
    {
        return $this->connectionManager()->connection();
    }

    private function connectionManager(): ConnectionManager
    {
        if ($this->connectionManager !== null) {
            return $this->connectionManager;
        }

        $configValues = [
            'driver' => (string) (getenv('DB_CONNECTION') ?: $_ENV['DB_CONNECTION'] ?? 'mysql'),
            'host' => (string) (getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost'),
            'port' => (int) (getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? 3306),
            'database' => (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? ''),
            'username' => (string) (getenv('DB_USERNAME') ?: $_ENV['DB_USERNAME'] ?? ''),
            'password' => (string) (getenv('DB_PASSWORD') ?: $_ENV['DB_PASSWORD'] ?? ''),
            'charset' => (string) (getenv('DB_CHARSET') ?: $_ENV['DB_CHARSET'] ?? 'utf8mb4'),
        ];

        $this->connectionManager = new ConnectionManager(new ConnectionFactory(), new DatabaseConfig($configValues));

        return $this->connectionManager;
    }

    private function compileCreateTable(Blueprint $blueprint): string
    {
        $definitions = [];
        $primaryKeys = [];

        foreach ($blueprint->getColumns() as $column) {
            $definitions[] = $this->compileColumn($column);

            if ($column->primary()) {
                $primaryKeys[] = $this->quoteIdentifier($column->name());
            }
        }

        foreach ($blueprint->getIndexes() as $index) {
            $definitions[] = $this->compileIndex($index, $blueprint->getTable());
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $foreignDefinition = $this->compileForeignKey($foreignKey, $blueprint->getTable());

            if ($foreignDefinition !== '') {
                $definitions[] = $foreignDefinition;
            }
        }

        if ($primaryKeys !== []) {
            $definitions[] = sprintf('PRIMARY KEY (%s)', implode(', ', $primaryKeys));
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $this->quoteIdentifier($blueprint->getTable()),
            implode(', ', $definitions)
        );

        return $sql . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function compileColumn(Column $column): string
    {
        $definition = sprintf('%s %s', $this->quoteIdentifier($column->name()), $this->compileColumnType($column));
        $definition .= $column->nullable() ? ' NULL' : ' NOT NULL';

        if ($column->default() !== null) {
            $definition .= sprintf(' DEFAULT %s', $this->compileDefaultValue($column->default()));
        }

        if ($column->unique()) {
            $definition .= ' UNIQUE';
        }

        if ($column->autoIncrement()) {
            $definition .= ' AUTO_INCREMENT';
        }

        if ($column->comment() !== null) {
            $definition .= sprintf(" COMMENT '%s'", addslashes($column->comment()));
        }

        return $definition;
    }

    private function compileColumnType(Column $column): string
    {
        return match ($column->type()) {
            'varchar' => sprintf('VARCHAR(%d)', $column->length() ?? 255),
            'char' => sprintf('CHAR(%d)', $column->length() ?? 1),
            'int' => sprintf('INT(%d)%s', $column->length() ?? 11, $column->unsigned() ? ' UNSIGNED' : ''),
            'bigint' => sprintf('BIGINT%s', $column->unsigned() ? ' UNSIGNED' : ''),
            'decimal' => sprintf('DECIMAL(%d, %d)', $column->precision() ?? 8, $column->scale() ?? 2),
            'text' => 'TEXT',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'boolean' => 'BOOLEAN',
            default => 'VARCHAR(255)',
        };
    }

    private function compileIndex(array $index, string $table): string
    {
        $columns = array_map(fn (string $column) => $this->quoteIdentifier($column), $index['columns']);
        $name = $index['name'] ?? $this->generateIndexName($table, $index['columns']);

        return sprintf('KEY %s (%s)', $this->quoteIdentifier($name), implode(', ', $columns));
    }

    private function generateIndexName(string $table, array $columns): string
    {
        return sprintf('%s_%s_idx', $table, implode('_', $columns));
    }

    private function compileForeignKey(array $foreignKey, string $table): string
    {
        if ($foreignKey['references'] === null || $foreignKey['on'] === null) {
            return '';
        }

        $name = $foreignKey['name'] ?? sprintf('%s_%s_foreign', $table, $foreignKey['column']);
        $definition = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($foreignKey['column']),
            $this->quoteIdentifier($foreignKey['on']),
            $this->quoteIdentifier($foreignKey['references'])
        );

        if ($foreignKey['onDelete'] !== null) {
            $definition .= sprintf(' ON DELETE %s', $foreignKey['onDelete']);
        }

        if ($foreignKey['onUpdate'] !== null) {
            $definition .= sprintf(' ON UPDATE %s', $foreignKey['onUpdate']);
        }

        return $definition;
    }

    private function compileDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return sprintf("'%s'", $value->format('Y-m-d H:i:s'));
        }

        return sprintf("'%s'", addslashes((string) $value));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return sprintf('`%s`', str_replace('`', '``', $identifier));
    }
}
