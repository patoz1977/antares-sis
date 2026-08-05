<?php

declare(strict_types=1);

namespace Tests;

use Core\Application\TransactionRunner;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\PdoTransactionRunner;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Tests\Support\TestRunner;
use Throwable;

function registerTransactionRunnerTests(TestRunner $runner): void
{
    $runner->add('TransactionRunner exposes only the approved callback boundary', function (): void {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(TransactionRunner::class))->getMethods(),
        );
        sort($methods, SORT_STRING);

        assertTransactionRunner($methods === ['run'], 'TransactionRunner contract was expanded.');
        assertTransactionRunner(
            !str_contains(
                (string) file_get_contents(dirname(__DIR__) . '/core/Application/TransactionRunner.php'),
                'Core\\Database'
            ),
            'TransactionRunner depends on database infrastructure.'
        );
    });

    $runner->add('PdoTransactionRunner executes and commits one transaction with exact result', function (): void {
        [$transactions, $connection] = pdoTransactionRunnerFixture();
        $result = new stdClass();

        $actual = $transactions->run(function () use ($connection, $result): stdClass {
            assertTransactionRunner(
                $connection->inTransaction(),
                'Callback did not execute inside the owned transaction.'
            );
            $connection->exec("INSERT INTO transaction_probe (value) VALUES ('committed')");

            return $result;
        });

        assertTransactionRunner($actual === $result, 'TransactionRunner changed the callback result.');
        assertTransactionRunner(!$connection->inTransaction(), 'Committed transaction remained open.');
        assertTransactionRunner(
            (int) $connection->query('SELECT COUNT(*) FROM transaction_probe')->fetchColumn() === 1,
            'Successful callback was not committed.'
        );
    });

    $runner->add('PdoTransactionRunner rolls back and propagates the same exception', function (): void {
        [$transactions, $connection] = pdoTransactionRunnerFixture();
        $failure = new RuntimeException('original transaction failure');
        $caught = null;

        try {
            $transactions->run(function () use ($connection, $failure): never {
                $connection->exec("INSERT INTO transaction_probe (value) VALUES ('rolled back')");
                throw $failure;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        assertTransactionRunner($caught === $failure, 'TransactionRunner replaced the original exception.');
        assertTransactionRunner(!$connection->inTransaction(), 'Rolled-back transaction remained open.');
        assertTransactionRunner(
            (int) $connection->query('SELECT COUNT(*) FROM transaction_probe')->fetchColumn() === 0,
            'Failed callback left persisted changes.'
        );
    });

    $runner->add('PdoTransactionRunner rejects an existing transaction before callback', function (): void {
        [$transactions, $connection] = pdoTransactionRunnerFixture();
        $connection->beginTransaction();
        $executed = false;

        try {
            assertTransactionRunnerThrows(
                function () use ($transactions, &$executed): void {
                    $transactions->run(function () use (&$executed): void {
                        $executed = true;
                    });
                },
                RuntimeException::class,
            );
            assertTransactionRunner(!$executed, 'Callback ran inside a pre-existing transaction.');
            assertTransactionRunner(
                $connection->inTransaction(),
                'TransactionRunner altered a transaction it did not own.'
            );
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    });

    $runner->add('PdoTransactionRunner owns only one strict PDO transaction boundary', function (): void {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/core/Database/PdoTransactionRunner.php'
        );
        $constructor = (new ReflectionClass(PdoTransactionRunner::class))->getConstructor();
        $parameter = $constructor?->getParameters()[0] ?? null;

        assertTransactionRunner(
            $parameter?->getType()?->__toString() === ConnectionManager::class,
            'PdoTransactionRunner does not depend on ConnectionManager.'
        );
        assertTransactionRunner(substr_count($source, 'beginTransaction()') === 1, 'Unexpected begin path.');
        assertTransactionRunner(substr_count($source, 'commit()') === 1, 'Unexpected commit path.');
        assertTransactionRunner(substr_count($source, 'rollBack()') === 1, 'Unexpected rollback path.');
        assertTransactionRunner(!str_contains(strtolower($source), 'savepoint'), 'Savepoints were added.');
        assertTransactionRunner(
            str_contains($source, "if (!\$this->connection->beginTransaction())")
            && str_contains($source, "if (!\$this->connection->commit())")
            && str_contains($source, "&& !\$this->connection->rollBack()"),
            'PDO transaction operation results are not checked.'
        );
    });
}

/** @return array{PdoTransactionRunner, PDO} */
function pdoTransactionRunnerFixture(): array
{
    $connection = new PDO('sqlite::memory:');
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->exec('CREATE TABLE transaction_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $manager = new ConnectionManager(new ConnectionFactory(), new DatabaseConfig([
        'driver' => 'mysql',
        'host' => 'not-used',
        'port' => 3306,
        'database' => 'not-used',
        'username' => 'not-used',
        'password' => 'not-used',
        'charset' => 'utf8mb4',
    ]));
    $property = new ReflectionProperty(ConnectionManager::class, 'connection');
    $property->setValue($manager, $connection);

    return [new PdoTransactionRunner($manager), $connection];
}

function assertTransactionRunner(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertTransactionRunnerThrows(callable $operation, string $expectedClass): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException(
            sprintf('Expected %s, got %s.', $expectedClass, $exception::class),
            previous: $exception,
        );
    }

    throw new RuntimeException(sprintf('Expected %s was not thrown.', $expectedClass));
}
