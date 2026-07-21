<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;

abstract class Migration implements MigrationInterface
{
    abstract public function up(PDO $connection): void;

    abstract public function down(PDO $connection): void;

    abstract public function version(): string;
}
