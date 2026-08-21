<?php

declare(strict_types=1);

namespace App\AcademicCore\Infrastructure\Persistence;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicSectionReference;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final class PdoAcademicPlacementReferenceProvider implements AcademicPlacementReferenceProvider
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findGradeById(int $gradeId): ?AcademicGradeReference
    {
        $statement = $this->connection->prepare($this->gradeSql() . ' WHERE g.id = :id');
        $statement->execute([':id' => $gradeId]);

        return $this->mapUniqueGrade($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findSectionById(int $sectionId): ?AcademicSectionReference
    {
        $statement = $this->connection->prepare(
            'SELECT s.id, s.code, s.name, status_row.code AS status_code, '
            . 'status_type.code AS status_type_code FROM sections s '
            . 'INNER JOIN statuses status_row ON status_row.id = s.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
            . 'WHERE s.id = :id'
        );
        $statement->execute([':id' => $sectionId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('Section identity resolved more than one row.');
        }

        return $rows === [] ? null : $this->mapSection($rows[0]);
    }

    public function findNextActiveGradeAfterSortOrder(int $sortOrder): ?AcademicGradeReference
    {
        if ($sortOrder <= 0) {
            throw new RuntimeException('Grade sort order must be positive.');
        }

        $statement = $this->connection->prepare(
            $this->gradeSql()
            . ' WHERE status_type.code = :statusType AND status_row.code = :statusCode '
            . 'AND g.sort_order > :sortOrder ORDER BY g.sort_order ASC, g.id ASC LIMIT 1'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => 'ACTIVE',
            ':sortOrder' => $sortOrder,
        ]);

        return $this->mapUniqueGrade($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function gradeSql(): string
    {
        return 'SELECT g.id, g.code, g.name, g.sort_order, status_row.code AS status_code, '
            . 'status_type.code AS status_type_code FROM grades g '
            . 'INNER JOIN statuses status_row ON status_row.id = g.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id';
    }

    /** @param list<array<string, mixed>> $rows */
    private function mapUniqueGrade(array $rows): ?AcademicGradeReference
    {
        if (count($rows) > 1) {
            throw new RuntimeException('Grade lookup resolved more than one row.');
        }

        return $rows === [] ? null : $this->mapGrade($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private function mapGrade(array $row): AcademicGradeReference
    {
        $this->assertGeneralStatus($row);

        return new AcademicGradeReference(
            $this->positiveInt($row['id'], 'Grade id'),
            $this->string($row['code'], 'Grade code'),
            $this->string($row['name'], 'Grade name'),
            $this->string($row['status_code'], 'Grade status'),
            $this->positiveInt($row['sort_order'], 'Grade sort order'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapSection(array $row): AcademicSectionReference
    {
        $this->assertGeneralStatus($row);

        return new AcademicSectionReference(
            $this->positiveInt($row['id'], 'Section id'),
            $this->string($row['code'], 'Section code'),
            $this->string($row['name'], 'Section name'),
            $this->string($row['status_code'], 'Section status'),
        );
    }

    /** @param array<string, mixed> $row */
    private function assertGeneralStatus(array $row): void
    {
        if ($this->string($row['status_type_code'], 'Academic reference status type') !== self::STATUS_TYPE) {
            throw new RuntimeException('Academic reference status does not belong to GENERAL_STATUS.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new RuntimeException($label . ' must be a persisted positive integer.');
        }
        if ($integer <= 0) {
            throw new RuntimeException($label . ' must be a persisted positive integer.');
        }

        return $integer;
    }

    private function string(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($label . ' must be a persisted non-empty string.');
        }

        return $value;
    }
}
