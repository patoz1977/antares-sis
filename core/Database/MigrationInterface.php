<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;

interface MigrationInterface
{
    public function up(PDO $connection): void;

    public function down(PDO $connection): void;

    public function version(): string;
}
