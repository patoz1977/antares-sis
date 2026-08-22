<?php

declare(strict_types=1);

namespace App\Representative\Infrastructure\Persistence;

use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final class PdoRepresentativeRepository implements RepresentativeRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(RepresentativeId $id): ?Representative
    {
        return $this->findByIdWithLock($id, false);
    }

    public function findByIdForUpdate(RepresentativeId $id): ?Representative
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Representative row lock requires an active transaction.');
        }

        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $lock = $this->connection->prepare(
                'SELECT id FROM representatives WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $lock->execute([':id' => $id->value()]);
            if ($lock->fetchColumn() === false) {
                return null;
            }
        }

        return $this->findByIdWithLock($id, true);
    }

    private function findByIdWithLock(RepresentativeId $id, bool $forUpdate): ?Representative
    {
        $sql = $this->selectSql() . ' WHERE r.id = :id LIMIT 1';
        if ($forUpdate && $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' LOCK IN SHARE MODE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $id->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findByPersonId(PersonId $personId): ?Representative
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE r.person_id = :personId LIMIT 1'
        );
        $statement->execute([':personId' => $personId->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function save(Representative $representative): Representative
    {
        $statusId = $this->resolveStatusId($representative->status());

        if ($representative->id() === null) {
            return $this->insert($representative, $statusId);
        }

        return $this->update($representative, $statusId);
    }

    private function insert(Representative $representative, int $statusId): Representative
    {
        $statement = $this->connection->prepare(
            'INSERT INTO representatives ('
            . 'person_id, occupation, company, position, work_phone, work_email, status_id'
            . ') VALUES ('
            . ':personId, :occupation, :company, :position, :workPhone, :workEmail, :statusId'
            . ')'
        );
        $values = $this->persistenceValues($representative, $statusId);
        $values[':personId'] = $representative->personId()->value();
        $statement->execute($values);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Representative insert did not affect exactly one row.');
        }

        $generatedId = (int) $this->connection->lastInsertId();
        if ($generatedId <= 0) {
            throw new RuntimeException('Representative insert did not produce a positive database identity.');
        }

        $persisted = $this->findById(new RepresentativeId($generatedId));
        if ($persisted === null) {
            throw new RuntimeException('Inserted Representative could not be reconstructed.');
        }

        return $persisted;
    }

    private function update(Representative $representative, int $statusId): Representative
    {
        $id = $representative->id();
        if ($id === null) {
            throw new RuntimeException('A Representative without persisted identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE representatives SET '
            . 'occupation = :occupation, company = :company, position = :position, '
            . 'work_phone = :workPhone, work_email = :workEmail, status_id = :statusId '
            . 'WHERE id = :id'
        );
        $values = $this->persistenceValues($representative, $statusId);
        $values[':id'] = $id->value();
        $statement->execute($values);

        $affectedRows = $statement->rowCount();
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException('Representative update did not affect zero or one row.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('Representative update failed because the persisted row disappeared.');
        }

        if ($affectedRows === 0 && !$this->sameState($persisted, $representative)) {
            throw new RuntimeException('Representative update did not persist the requested state.');
        }

        return $persisted;
    }

    private function resolveStatusId(RepresentativeStatus $status): int
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

        if (count($rows) !== 1) {
            throw new RuntimeException('Representative status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, int|string|null> */
    private function persistenceValues(Representative $representative, int $statusId): array
    {
        $employment = $representative->employmentInformation();

        return [
            ':occupation' => $employment?->occupation(),
            ':company' => $employment?->companyName(),
            ':position' => $employment?->position(),
            ':workPhone' => $employment?->workPhone(),
            ':workEmail' => $employment?->workEmail(),
            ':statusId' => $statusId,
        ];
    }

    private function selectSql(): string
    {
        return 'SELECT r.id, r.person_id, r.occupation, r.company, r.position, '
            . 'r.work_phone, r.work_email, r.status_id, '
            . 's.code AS status_code, st.code AS status_type_code '
            . 'FROM representatives r '
            . 'INNER JOIN statuses s ON s.id = r.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    private function mapRow(array|false $row): ?Representative
    {
        if ($row === false) {
            return null;
        }

        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('Representative status does not belong to GENERAL_STATUS.');
        }

        $status = RepresentativeStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('Representative has an unsupported GENERAL_STATUS value.');
        }

        return new Representative(
            new RepresentativeId((int) $row['id']),
            new PersonId((int) $row['person_id']),
            $this->mapEmploymentInformation($row),
            $status,
        );
    }

    private function mapEmploymentInformation(array $row): ?EmploymentInformation
    {
        $occupation = $this->nullableString($row['occupation']);
        $company = $this->nullableString($row['company']);
        $position = $this->nullableString($row['position']);
        $workPhone = $this->nullableString($row['work_phone']);
        $workEmail = $this->nullableString($row['work_email']);

        if ($occupation === null
            && $company === null
            && $position === null
            && $workPhone === null
            && $workEmail === null
        ) {
            return null;
        }

        return new EmploymentInformation($occupation, $company, $position, $workPhone, $workEmail);
    }

    private function sameState(Representative $persisted, Representative $representative): bool
    {
        $persistedId = $persisted->id();
        $representativeId = $representative->id();
        if ($persistedId === null
            || $representativeId === null
            || !$persistedId->equals($representativeId)
        ) {
            return false;
        }

        return $persisted->personId()->equals($representative->personId())
            && $this->sameEmploymentInformation(
                $persisted->employmentInformation(),
                $representative->employmentInformation(),
            )
            && $persisted->status() === $representative->status();
    }

    private function sameEmploymentInformation(
        ?EmploymentInformation $left,
        ?EmploymentInformation $right,
    ): bool {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
