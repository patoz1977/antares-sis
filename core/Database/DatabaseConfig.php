<?php

declare(strict_types=1);

namespace Core\Database;

use InvalidArgumentException;

final class DatabaseConfig
{
    private string $driver;

    private string $host;

    private int $port;

    private string $database;

    private string $username;

    private string $password;

    private string $charset;

    public function __construct(array $config)
    {
        $requiredKeys = ['driver', 'host', 'port', 'database', 'username', 'password', 'charset'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $config)) {
                throw new InvalidArgumentException(sprintf('Missing required database config key: %s', $key));
            }
        }

        $this->driver = $config['driver'];
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->database = $config['database'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->charset = $config['charset'];
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function charset(): string
    {
        return $this->charset;
    }
}
