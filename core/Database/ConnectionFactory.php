<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;
use PDOException;

final class ConnectionFactory
{
    public function create(DatabaseConfig $config): PDO
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config->driver(),
            $config->host(),
            $config->port(),
            $config->database(),
            $config->charset()
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($config->driver() === 'mysql') {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }

        try {
            $connection = new PDO($dsn, $config->username(), $config->password(), $options);

            if ($config->driver() === 'mysql') {
                // Identity timestamps are persisted and read as UTC regardless of deployment timezone.
                $connection->exec("SET time_zone = '+00:00'");
            }

            return $connection;
        } catch (PDOException $exception) {
            throw new DatabaseException('Database connection failed.', previous: $exception);
        }
    }
}
