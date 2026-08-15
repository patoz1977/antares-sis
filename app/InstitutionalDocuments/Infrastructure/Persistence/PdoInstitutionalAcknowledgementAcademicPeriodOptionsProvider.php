<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Infrastructure\Persistence;

use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOption;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use Core\Database\ConnectionManager;
use PDO;

final class PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider implements
    InstitutionalAcknowledgementAcademicPeriodOptionsProvider
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function all(): array
    {
        $statement = $this->connection->query(
            'SELECT id, code, name, starts_on, ends_on '
            . 'FROM academic_periods ORDER BY starts_on DESC, id DESC'
        );

        return array_map(self::option(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?InstitutionalAcknowledgementAcademicPeriodOption
    {
        $statement = $this->connection->prepare(
            'SELECT id, code, name, starts_on, ends_on FROM academic_periods WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::option($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function option(array $row): InstitutionalAcknowledgementAcademicPeriodOption
    {
        return new InstitutionalAcknowledgementAcademicPeriodOption(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
        );
    }
}
