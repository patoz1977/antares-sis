<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Http\FamilyResourceFormOption;
use App\Family\Http\FamilyResourceFormOptions;
use App\Family\Http\FamilyResourceFormOptionsProvider;
use Core\Database\ConnectionManager;
use PDO;

final class PdoFamilyResourceFormOptionsProvider implements FamilyResourceFormOptionsProvider
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function get(): FamilyResourceFormOptions
    {
        return new FamilyResourceFormOptions(
            $this->activeOptions(
                'SELECT id, code, name FROM relationship_types '
                . 'WHERE is_active = TRUE ORDER BY name, id'
            ),
            $this->activeOptions(
                'SELECT id, code, name FROM document_types '
                . 'WHERE is_active = TRUE ORDER BY name, id'
            ),
        );
    }

    /** @return list<FamilyResourceFormOption> */
    private function activeOptions(string $sql): array
    {
        $statement = $this->connection->query($sql);

        return array_map(
            static fn (array $row): FamilyResourceFormOption => new FamilyResourceFormOption(
                (int) $row['id'],
                (string) $row['code'],
                (string) $row['name'],
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
