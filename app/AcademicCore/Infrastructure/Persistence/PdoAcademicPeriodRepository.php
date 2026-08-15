<?php

declare(strict_types=1);

namespace App\AcademicCore\Infrastructure\Persistence;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PdoAcademicPeriodRepository implements AcademicPeriodRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(AcademicPeriodId $id): ?AcademicPeriod
    {
        $statement = $this->connection->prepare($this->selectSql() . ' WHERE ap.id = :id');
        $statement->execute([':id' => $id->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('AcademicPeriod identity resolved more than one row.');
        }

        return $rows === [] ? null : $this->mapRow($rows[0]);
    }

    public function findActive(): ?AcademicPeriod
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE st.code = :statusType AND status_row.code = :statusCode ORDER BY ap.id ASC'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => AcademicPeriodStatus::Active->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 1) {
            throw new AcademicPeriodOperationalStateConflict(
                'More than one ACTIVE AcademicPeriod exists; operational context is ambiguous.'
            );
        }

        return $rows === [] ? null : $this->mapRow($rows[0]);
    }

    public function save(AcademicPeriod $period): AcademicPeriod
    {
        $id = $period->id();
        if ($id === null) {
            throw new RuntimeException('An unpersisted AcademicPeriod cannot be saved by lifecycle operations.');
        }

        $statement = $this->connection->prepare(
            'UPDATE academic_periods SET status_id = :statusId WHERE id = :id'
        );
        $statement->execute([
            ':statusId' => $this->resolveStatusId($period->status()),
            ':id' => $id->value(),
        ]);
        if (!in_array($statement->rowCount(), [0, 1], true)) {
            throw new RuntimeException('AcademicPeriod update did not affect zero or one row.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('AcademicPeriod update failed because the row disappeared.');
        }
        if (!$this->sameState($persisted, $period)) {
            throw new RuntimeException('AcademicPeriod update did not persist the requested state.');
        }

        return $persisted;
    }

    public function lockOperationalTransition(): void
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('AcademicPeriod operational lock requires an active transaction.');
        }

        $sql = 'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode';
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => AcademicPeriodStatus::Active->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || (int) $rows[0] <= 0) {
            throw new RuntimeException(
                'AcademicPeriod operational lock requires exactly one GENERAL_STATUS/ACTIVE row.'
            );
        }
    }

    private function resolveStatusId(AcademicPeriodStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => $status->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || (int) $rows[0] <= 0) {
            throw new RuntimeException(
                'AcademicPeriod status must resolve to exactly one GENERAL_STATUS row.'
            );
        }

        return (int) $rows[0];
    }

    private function selectSql(): string
    {
        return 'SELECT ap.id, ap.code, ap.name, ap.starts_on, ap.ends_on, '
            . 'status_row.code AS status_code, st.code AS status_type_code '
            . 'FROM academic_periods ap '
            . 'INNER JOIN statuses status_row ON status_row.id = ap.status_id '
            . 'INNER JOIN status_types st ON st.id = status_row.status_type_id';
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): AcademicPeriod
    {
        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('AcademicPeriod status does not belong to GENERAL_STATUS.');
        }
        $status = AcademicPeriodStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('AcademicPeriod has an unsupported GENERAL_STATUS value.');
        }

        return new AcademicPeriod(
            new AcademicPeriodId((int) $row['id']),
            new AcademicPeriodCode((string) $row['code']),
            new AcademicPeriodName((string) $row['name']),
            new AcademicPeriodDateRange(
                $this->date((string) $row['starts_on']),
                $this->date((string) $row['ends_on']),
            ),
            $status,
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('AcademicPeriod has an invalid persisted date.');
        }

        return $date;
    }

    private function sameState(AcademicPeriod $left, AcademicPeriod $right): bool
    {
        $leftId = $left->id();
        $rightId = $right->id();

        return $leftId !== null
            && $rightId !== null
            && $leftId->equals($rightId)
            && $left->code()->equals($right->code())
            && $left->name()->equals($right->name())
            && $left->dates()->equals($right->dates())
            && $left->status() === $right->status();
    }
}
