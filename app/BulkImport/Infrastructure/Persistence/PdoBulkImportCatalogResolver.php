<?php

declare(strict_types=1);

namespace App\BulkImport\Infrastructure\Persistence;

use App\BulkImport\Application\Catalog\BulkImportCatalogKind;
use App\BulkImport\Application\Catalog\BulkImportCatalogResolver;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final readonly class PdoBulkImportCatalogResolver implements BulkImportCatalogResolver
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findActiveCatalogId(
        BulkImportCatalogKind $kind,
        string $code,
        bool $forUpdate = false,
    ): ?int {
        $table = match ($kind) {
            BulkImportCatalogKind::Sex => 'sexes',
            BulkImportCatalogKind::DocumentType => 'document_types',
            BulkImportCatalogKind::RelationshipType => 'relationship_types',
        };
        $sql = sprintf(
            'SELECT id FROM %s WHERE code = :code AND is_active = TRUE LIMIT 1',
            $table,
        );

        return $this->fetchId($sql, [':code' => trim($code)], $forUpdate);
    }

    public function findActiveStatusId(
        string $statusTypeCode,
        string $statusCode,
        bool $forUpdate = false,
    ): ?int {
        return $this->fetchId(
            'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :type AND st.is_active = TRUE '
            . 'AND s.code = :code AND s.is_active = TRUE LIMIT 1',
            [':type' => trim($statusTypeCode), ':code' => trim($statusCode)],
            $forUpdate,
        );
    }

    /** @param array<string, string> $parameters */
    private function fetchId(string $sql, array $parameters, bool $forUpdate): ?int
    {
        if ($forUpdate) {
            if (!$this->connection->inTransaction()) {
                throw new RuntimeException('Bulk Import catalog row lock requires an active transaction.');
            }
            if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
