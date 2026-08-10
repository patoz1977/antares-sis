<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Domain\FamilyStatus;
use App\Family\Http\FamilyFormOption;
use App\Family\Http\FamilyFormOptions;
use App\Family\Http\FamilyFormOptionsProvider;
use Core\Database\ConnectionManager;
use PDO;

final class PdoFamilyFormOptionsProvider implements FamilyFormOptionsProvider
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function get(): FamilyFormOptions
    {
        $statement = $this->connection->query(
            'SELECT id, code, name FROM relationship_types '
            . 'WHERE is_active = TRUE ORDER BY name, id'
        );
        $relationshipTypes = array_map(
            static fn (array $row): FamilyFormOption => new FamilyFormOption(
                (int) $row['id'],
                (string) $row['code'],
                (string) $row['name'],
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );

        return new FamilyFormOptions(
            $relationshipTypes,
            [FamilyStatus::Active, FamilyStatus::Inactive],
        );
    }
}
