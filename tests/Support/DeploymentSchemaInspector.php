<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class DeploymentSchemaInspector
{
    /** @return array<string, list<array<string, int|string|null>>> */
    public function snapshot(PDO $connection): array
    {
        return [
            'tables' => $this->rows($connection, <<<'SQL'
                SELECT table_name, engine, table_collation
                FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                ORDER BY table_name
                SQL),
            'columns' => $this->rows($connection, <<<'SQL'
                SELECT table_name, ordinal_position, column_name, column_type, is_nullable,
                       column_default, extra, generation_expression, character_set_name, collation_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                ORDER BY table_name, ordinal_position
                SQL),
            'indexes' => $this->rows($connection, <<<'SQL'
                SELECT table_name, index_name, non_unique, seq_in_index, column_name,
                       sub_part, index_type, collation, nullable
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                ORDER BY table_name, index_name, seq_in_index
                SQL),
            'constraints' => $this->rows($connection, <<<'SQL'
                SELECT table_name, constraint_name, constraint_type
                FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name
                SQL),
            'constraint_columns' => $this->rows($connection, <<<'SQL'
                SELECT table_name, constraint_name, ordinal_position, column_name,
                       referenced_table_name, referenced_column_name, position_in_unique_constraint
                FROM information_schema.key_column_usage
                WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name, ordinal_position
                SQL),
            'foreign_keys' => $this->rows($connection, <<<'SQL'
                SELECT table_name, constraint_name, referenced_table_name, update_rule, delete_rule
                FROM information_schema.referential_constraints
                WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name
                SQL),
            'checks' => $this->rows($connection, <<<'SQL'
                SELECT tc.table_name, cc.constraint_name, cc.check_clause
                FROM information_schema.check_constraints cc
                INNER JOIN information_schema.table_constraints tc
                    ON tc.constraint_schema = cc.constraint_schema
                    AND tc.constraint_name = cc.constraint_name
                    AND tc.constraint_type = 'CHECK'
                WHERE cc.constraint_schema = DATABASE()
                ORDER BY tc.table_name, cc.constraint_name
                SQL),
        ];
    }

    /**
     * @param array<string, list<array<string, int|string|null>>> $expected
     * @param array<string, list<array<string, int|string|null>>> $actual
     */
    public function difference(array $expected, array $actual): string
    {
        $messages = [];
        foreach (array_keys($expected) as $category) {
            if (($expected[$category] ?? null) === ($actual[$category] ?? null)) {
                continue;
            }

            $expectedRows = $expected[$category] ?? [];
            $actualRows = $actual[$category] ?? [];
            $messages[] = sprintf(
                '%s differs (expected rows=%d sha256=%s; actual rows=%d sha256=%s; first difference=%s)',
                $category,
                count($expectedRows),
                $this->digest($expectedRows),
                count($actualRows),
                $this->digest($actualRows),
                $this->firstDifference($expectedRows, $actualRows),
            );
        }

        return $messages === [] ? '(none)' : implode("\n", $messages);
    }

    /** @return list<array<string, int|string|null>> */
    private function rows(PDO $connection, string $sql): array
    {
        $rows = $connection->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            foreach ($row as $key => $value) {
                if ($value !== null) {
                    $row[$key] = (string) $value;
                }
            }

            return $row;
        }, $rows);
    }

    /** @param list<array<string, int|string|null>> $rows */
    private function digest(array $rows): string
    {
        return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<array<string, int|string|null>> $expected
     * @param list<array<string, int|string|null>> $actual
     */
    private function firstDifference(array $expected, array $actual): string
    {
        $length = max(count($expected), count($actual));
        for ($index = 0; $index < $length; $index++) {
            if (($expected[$index] ?? null) !== ($actual[$index] ?? null)) {
                return sprintf(
                    'row %d expected=%s actual=%s',
                    $index + 1,
                    $this->represent($expected[$index] ?? null),
                    $this->represent($actual[$index] ?? null),
                );
            }
        }

        return '(not located)';
    }

    private function represent(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '(unrepresentable)' : $encoded;
    }
}
