<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Tests\Support\DeploymentInstallSql;
use Tests\Support\DeploymentSchemaInspector;
use Tests\Support\TestRunner;

function registerDeploymentInstallationSupportTests(TestRunner $runner): void
{
    $runner->add('DEPLOY-001 production container resolves the complete Bulk Import workbook reader graph', function (): void {
        $root = dirname(__DIR__);
        $app = require $root . '/bootstrap/app.php';

        assertSameValue(
            true,
            $app->container()->make(\App\BulkImport\Application\Contract\BulkImportWorkbookReader::class)
                instanceof \App\BulkImport\Infrastructure\Xlsx\OpenSpoutBulkImportWorkbookReader,
        );
    });

    $runner->add('DEPLOY-001 install SQL loader accepts one external autonomous artifact', function (): void {
        $path = deployTemporarySqlPath();
        $sql = "CREATE TABLE example (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY);\n"
            . "INSERT INTO example (id) VALUES (1);\n";
        file_put_contents($path, $sql);

        try {
            $artifact = DeploymentInstallSql::load($path, dirname(__DIR__), 'not-present-in-artifact');

            assertSameValue(realpath($path), $artifact->path);
            assertSameValue(strlen($sql), $artifact->size);
            assertSameValue(hash('sha256', $sql), $artifact->sha256);
            assertSameValue($sql, $artifact->contents);
        } finally {
            deployRemoveTemporarySql($path);
        }
    });

    $runner->add('DEPLOY-001 install SQL loader rejects database and client commands', function (): void {
        foreach ([
            'CREATE DATABASE forbidden;',
            'DROP DATABASE forbidden;',
            "CREATE USER 'forbidden'@'localhost';",
            "ALTER USER 'forbidden'@'localhost';",
            "DROP USER 'forbidden'@'localhost';",
            'GRANT SELECT ON forbidden.* TO forbidden;',
            'REVOKE SELECT ON forbidden.* FROM forbidden;',
            'SET PASSWORD FOR forbidden = PASSWORD(\'forbidden\');',
            "LOAD DATA INFILE 'external.csv' INTO TABLE forbidden;",
            'USE forbidden;',
            'SOURCE another.sql',
            'DELIMITER //',
        ] as $sql) {
            $path = deployTemporarySqlPath();
            file_put_contents($path, $sql);

            try {
                assertThrows(
                    static fn () => DeploymentInstallSql::load($path, dirname(__DIR__), 'not-present'),
                    RuntimeException::class,
                );
            } finally {
                deployRemoveTemporarySql($path);
            }
        }
    });

    $runner->add('DEPLOY-001 install SQL loader rejects plaintext passwords and credential labels', function (): void {
        foreach ([
            "INSERT INTO users (password_hash) VALUES ('known-rehearsal-password');",
            'SELECT E0041_DB_PASSWORD;',
            'SELECT DB_USERNAME;',
        ] as $sql) {
            $path = deployTemporarySqlPath();
            file_put_contents($path, $sql);

            try {
                assertThrows(
                    static fn () => DeploymentInstallSql::load(
                        $path,
                        dirname(__DIR__),
                        'known-rehearsal-password',
                    ),
                    RuntimeException::class,
                );
            } finally {
                deployRemoveTemporarySql($path);
            }
        }
    });

    $runner->add('DEPLOY-001 install SQL path boundary rejects repository artifacts', function (): void {
        $root = dirname(__DIR__);

        assertSameValue(
            true,
            DeploymentInstallSql::isInsideRoot($root . DIRECTORY_SEPARATOR . 'install.sql', $root),
        );
        assertSameValue(
            false,
            DeploymentInstallSql::isInsideRoot(dirname($root) . DIRECTORY_SEPARATOR . 'install.sql', $root),
        );
    });

    $runner->add('DEPLOY-001 schema parity diagnostics identify the exact material category', function (): void {
        $inspector = new DeploymentSchemaInspector();
        $expected = [
            'tables' => [['table_name' => 'persons', 'engine' => 'InnoDB']],
            'columns' => [['table_name' => 'persons', 'column_name' => 'id']],
        ];
        $actual = [
            'tables' => [['table_name' => 'persons', 'engine' => 'InnoDB']],
            'columns' => [['table_name' => 'persons', 'column_name' => 'person_id']],
        ];
        $difference = $inspector->difference($expected, $actual);

        assertSameValue(false, str_contains($difference, 'tables differs'));
        assertSameValue(true, str_contains($difference, 'columns differs'));
        assertSameValue(true, str_contains($difference, 'first difference=row 1'));
    });
}

function deployTemporarySqlPath(): string
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-deploy-support-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the DEPLOY-001 SQL test directory.');
    }

    return $directory . DIRECTORY_SEPARATOR . 'install.sql';
}

function deployRemoveTemporarySql(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }

    $directory = dirname($path);
    if (is_dir($directory)) {
        rmdir($directory);
    }
}
