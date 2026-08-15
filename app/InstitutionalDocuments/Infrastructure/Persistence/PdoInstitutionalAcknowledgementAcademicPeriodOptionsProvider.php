<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Infrastructure\Persistence;

use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOption;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

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
            'SELECT ap.id, ap.code, ap.name, ap.starts_on, ap.ends_on, '
            . 's.code AS status_code, st.code AS status_type_code '
            . 'FROM academic_periods ap '
            . 'INNER JOIN statuses s ON s.id = ap.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'ORDER BY ap.starts_on DESC, ap.id DESC'
        );

        return array_map(self::option(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?InstitutionalAcknowledgementAcademicPeriodOption
    {
        $statement = $this->connection->prepare(
            'SELECT ap.id, ap.code, ap.name, ap.starts_on, ap.ends_on, '
            . 's.code AS status_code, st.code AS status_type_code '
            . 'FROM academic_periods ap '
            . 'INNER JOIN statuses s ON s.id = ap.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE ap.id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::option($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function option(array $row): InstitutionalAcknowledgementAcademicPeriodOption
    {
        $status = (string) $row['status_code'];
        if ((string) $row['status_type_code'] !== 'GENERAL_STATUS'
            || !in_array($status, ['ACTIVE', 'INACTIVE'], true)
        ) {
            throw new RuntimeException('AcademicPeriod option has an unsupported status.');
        }

        return new InstitutionalAcknowledgementAcademicPeriodOption(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['name'],
            (string) $row['starts_on'],
            (string) $row['ends_on'],
            $status,
        );
    }
}
