<?php

declare(strict_types=1);

namespace App\Person\Infrastructure\Persistence;

use App\Person\Http\PersonFormOption;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use Core\Database\ConnectionManager;
use PDO;

final class PdoPersonFormOptionsProvider implements PersonFormOptionsProvider
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function get(): PersonFormOptions
    {
        return new PersonFormOptions(
            $this->activeCatalog('document_types'),
            $this->activeCatalog('sexes'),
            $this->activeCatalog('marital_statuses'),
            $this->activeCatalog('education_levels'),
            $this->generalStatuses(),
        );
    }

    /** @return list<PersonFormOption> */
    private function activeCatalog(string $table): array
    {
        $statement = $this->connection->query(
            sprintf(
                'SELECT id, code, name FROM `%s` WHERE is_active = TRUE ORDER BY name, id',
                $table,
            )
        );

        return $this->mapOptions($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<PersonFormOption> */
    private function generalStatuses(): array
    {
        $statement = $this->connection->prepare(
            'SELECT s.id, s.code, s.name '
            . 'FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusTypeCode '
            . 'AND st.is_active = TRUE '
            . 'AND s.is_active = TRUE '
            . "AND s.code IN ('ACTIVE', 'INACTIVE') "
            . 'ORDER BY s.sort_order, s.name, s.id'
        );
        $statement->execute([':statusTypeCode' => 'GENERAL_STATUS']);

        return $this->mapOptions($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<PersonFormOption>
     */
    private function mapOptions(array $rows): array
    {
        $options = [];

        foreach ($rows as $row) {
            $options[] = new PersonFormOption(
                (int) $row['id'],
                (string) $row['code'],
                (string) $row['name'],
            );
        }

        return $options;
    }
}
