<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;

final class ConnectionManager
{
    private ConnectionFactory $factory;

    private DatabaseConfig $config;

    private ?PDO $connection = null;

    public function __construct(ConnectionFactory $factory, DatabaseConfig $config)
    {
        $this->factory = $factory;
        $this->config = $config;
    }

    public function connection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->factory->create($this->config);
        }

        return $this->connection;
    }
}
