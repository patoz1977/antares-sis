<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    private ConnectionManager $connectionManager;

    private string $migrationsPath;

    private string $seedersPath;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connectionManager = $connectionManager;
        $this->migrationsPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $this->seedersPath = dirname(__DIR__, 1) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
    }

    public function run(): void
    {
        $connection = $this->connectionManager->connection();

        $this->ensureMigrationsTable($connection);

        $executedMigrations = $this->executedMigrations($connection);
        $migrations = $this->loadMigrations();

        $pendingMigrations = [];
        foreach ($migrations as $migration) {
            if (!in_array($migration->version(), $executedMigrations, true)) {
                $pendingMigrations[] = $migration;
            }
        }

        if ($pendingMigrations === []) {
            echo "No pending migrations.\n";
        } else {
            $batch = $this->nextBatch($connection);
            foreach ($pendingMigrations as $migration) {
                $this->runMigration($connection, $migration, $batch);
                echo sprintf("Migration executed: %s\n", $migration->version());
            }
        }

        $this->runSeeders($connection);
        echo "Seeders executed.\n";

        echo "Done.\n";
    }

    private function ensureMigrationsTable(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'migration VARCHAR(255) NOT NULL,'
            . 'batch INT NOT NULL,'
            . 'executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE KEY migration_unique (migration)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function executedMigrations(PDO $connection): array
    {
        try {
            $statement = $connection->query('SELECT migration FROM migrations ORDER BY id');
        } catch (\Throwable) {
            return [];
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    private function loadMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }

            if (!preg_match('/^\d+_.+\.php$/', $file)) {
                continue;
            }

            $path = $this->migrationsPath . DIRECTORY_SEPARATOR . $file;
            require_once $path;

            $className = $this->classNameFromFileName($file);
            if (!class_exists($className)) {
                throw new RuntimeException(sprintf('Migration class not found: %s', $className));
            }

            $migration = new $className();
            if (!$migration instanceof MigrationInterface) {
                throw new RuntimeException(sprintf('Migration %s must implement MigrationInterface.', $className));
            }

            $migrations[] = $migration;
        }

        usort($migrations, static function (MigrationInterface $left, MigrationInterface $right): int {
            return strcmp($left->version(), $right->version());
        });

        return $migrations;
    }

    private function classNameFromFileName(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $parts = explode('_', $stem);

        $index = 0;
        while ($index < count($parts) && preg_match('/^\d+$/', $parts[$index])) {
            $index++;
        }

        $parts = array_slice($parts, $index);
        $classParts = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (strtolower($part) === 'create') {
                continue;
            }

            $classParts[] = str_replace(' ', '', ucwords(str_replace('_', ' ', $part)));
        }

        if ($classParts === []) {
            throw new RuntimeException(sprintf('Unable to derive migration class from file: %s', $fileName));
        }

        return 'Create' . implode('', $classParts);
    }

    private function nextBatch(PDO $connection): int
    {
        try {
            $statement = $connection->query('SELECT MAX(batch) AS max_batch FROM migrations');
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return 1;
        }

        return (int) ($row['max_batch'] ?? 0) + 1;
    }

    private function runMigration(PDO $connection, MigrationInterface $migration, int $batch): void
    {
        try {
            $startedTransaction = false;
            if (!$connection->inTransaction()) {
                $connection->beginTransaction();
                $startedTransaction = true;
            }

            $migration->up($connection);
            $statement = $connection->prepare('INSERT INTO migrations (migration, batch, executed_at) VALUES (:migration, :batch, :executedAt)');
            $statement->execute([
                ':migration' => $migration->version(),
                ':batch' => $batch,
                ':executedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);

            if ($startedTransaction && $connection->inTransaction()) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function runSeeders(PDO $connection): void
    {
        foreach ($this->loadSeeders() as $seeder) {
            $seeder->run($connection);
            echo sprintf("Seeder executed: %s\n", $seeder::class);
        }
    }

    private function loadSeeders(): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }

        $files = scandir($this->seedersPath);
        if ($files === false) {
            return [];
        }

        $seeders = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }

            $path = $this->seedersPath . DIRECTORY_SEPARATOR . $file;
            require_once $path;

            $className = 'Database\\Seeders\\' . pathinfo($file, PATHINFO_FILENAME);
            if (!class_exists($className)) {
                throw new RuntimeException(sprintf('Seeder class not found: %s', $className));
            }

            $seeder = new $className();
            if (!method_exists($seeder, 'run')) {
                throw new RuntimeException(sprintf('Seeder %s must have a run(PDO $connection) method.', $className));
            }

            $seeders[] = $seeder;
        }

        $priority = [
            'Database\\Seeders\\StatusTypeSeeder' => 10,
            'Database\\Seeders\\StatusSeeder' => 20,
            'Database\\Seeders\\AdminSeeder' => 30,
        ];

        usort($seeders, static function (object $left, object $right) use ($priority): int {
            $leftClass = $left::class;
            $rightClass = $right::class;

            $leftPriority = $priority[$leftClass] ?? 100;
            $rightPriority = $priority[$rightClass] ?? 100;

            if ($leftPriority === $rightPriority) {
                return strcmp($leftClass, $rightClass);
            }

            return $leftPriority <=> $rightPriority;
        });

        return $seeders;
    }
}
