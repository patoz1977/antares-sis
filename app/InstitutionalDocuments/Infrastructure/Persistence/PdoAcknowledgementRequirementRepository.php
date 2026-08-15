<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Infrastructure\Persistence;

use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;
use Throwable;

final class PdoAcknowledgementRequirementRepository implements AcknowledgementRequirementRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(AcknowledgementRequirementId $id): ?AcknowledgementRequirement
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE ar.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findByAcademicPeriodId(AcademicPeriodId $academicPeriodId): array
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE ar.academic_period_id = :academicPeriodId ORDER BY ar.id ASC'
        );
        $statement->execute([':academicPeriodId' => $academicPeriodId->value()]);

        return array_map(
            fn (array $row): AcknowledgementRequirement => $this->mapRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function lockForPostUseUpdate(
        AcknowledgementRequirementId $id,
    ): ?AcknowledgementRequirement {
        $rows = $this->lockedRows(
            ' WHERE ar.id = :id',
            [':id' => $id->value()],
        );
        if (count($rows) > 1) {
            throw new RuntimeException('Acknowledgement Requirement identity resolved more than one locked row.');
        }

        return $rows === [] ? null : $rows[0];
    }

    public function lockForCompletion(AcademicPeriodId $academicPeriodId): array
    {
        return $this->lockedRows(
            ' WHERE ar.academic_period_id = :academicPeriodId ORDER BY ar.id ASC',
            [':academicPeriodId' => $academicPeriodId->value()],
        );
    }

    public function hasAcknowledgements(AcknowledgementRequirementId $id): bool
    {
        $statement = $this->connection->prepare(
            'SELECT EXISTS('
            . 'SELECT 1 FROM representative_acknowledgements '
            . 'WHERE acknowledgement_requirement_id = :requirementId'
            . ')'
        );
        $statement->execute([':requirementId' => $id->value()]);
        $result = $statement->fetchColumn();

        if ($result === false || !in_array((string) $result, ['0', '1'], true)) {
            throw new RuntimeException('Acknowledgement history query returned an invalid result.');
        }

        return (string) $result === '1';
    }

    public function save(AcknowledgementRequirement $requirement): AcknowledgementRequirement
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction && !$this->connection->beginTransaction()) {
            throw new RuntimeException('Acknowledgement requirement persistence could not start its transaction.');
        }

        try {
            $statusId = $this->resolveStatusId($requirement->status());
            $persisted = $requirement->id() === null
                ? $this->insert($requirement, $statusId)
                : $this->update($requirement, $statusId);

            if ($ownsTransaction && !$this->connection->commit()) {
                throw new RuntimeException('Acknowledgement requirement persistence could not commit its transaction.');
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function insert(AcknowledgementRequirement $requirement, int $statusId): AcknowledgementRequirement
    {
        $statement = $this->connection->prepare(
            'INSERT INTO acknowledgement_requirements ('
            . 'academic_period_id, title, url, official_reference, status_id'
            . ') VALUES ('
            . ':academicPeriodId, :title, :url, :officialReference, :statusId'
            . ')'
        );
        $statement->execute($this->persistenceValues($requirement, $statusId, true));

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Acknowledgement requirement insert did not affect exactly one row.');
        }

        $generatedId = $this->generatedId('Acknowledgement requirement');
        $persisted = $this->findById(new AcknowledgementRequirementId($generatedId));
        if ($persisted === null || !$this->sameState($persisted, $requirement)) {
            throw new RuntimeException('Inserted acknowledgement requirement could not be reconstructed exactly.');
        }

        return $persisted;
    }

    private function update(AcknowledgementRequirement $requirement, int $statusId): AcknowledgementRequirement
    {
        $id = $requirement->id();
        if ($id === null) {
            throw new RuntimeException('An acknowledgement requirement without identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE acknowledgement_requirements SET '
            . 'title = :title, url = :url, official_reference = :officialReference, status_id = :statusId '
            . 'WHERE id = :id'
        );
        $values = $this->persistenceValues($requirement, $statusId, false);
        $values[':id'] = $id->value();
        $statement->execute($values);

        if (!in_array($statement->rowCount(), [0, 1], true)) {
            throw new RuntimeException('Acknowledgement requirement update affected an invalid row count.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('Acknowledgement requirement update target disappeared.');
        }
        if (!$this->sameState($persisted, $requirement)) {
            throw new RuntimeException('Acknowledgement requirement update did not persist the requested state.');
        }

        return $persisted;
    }

    private function resolveStatusId(AcknowledgementRequirementStatus $status): int
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
                'Acknowledgement requirement status must resolve to exactly one GENERAL_STATUS row.'
            );
        }

        return (int) $rows[0];
    }

    /** @return array<string, int|string|null> */
    private function persistenceValues(
        AcknowledgementRequirement $requirement,
        int $statusId,
        bool $includeAcademicPeriod,
    ): array {
        $values = [
            ':title' => $requirement->title()->value(),
            ':url' => $requirement->url()->value(),
            ':officialReference' => $requirement->officialReference()?->value(),
            ':statusId' => $statusId,
        ];

        if ($includeAcademicPeriod) {
            $values[':academicPeriodId'] = $requirement->academicPeriodId()->value();
        }

        return $values;
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): AcknowledgementRequirement
    {
        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('Acknowledgement requirement status does not belong to GENERAL_STATUS.');
        }

        $status = AcknowledgementRequirementStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('Acknowledgement requirement has an unsupported GENERAL_STATUS value.');
        }

        return AcknowledgementRequirement::reconstitute(
            new AcknowledgementRequirementId((int) $row['id']),
            new AcademicPeriodId((int) $row['academic_period_id']),
            new AcknowledgementRequirementTitle((string) $row['title']),
            new AcknowledgementRequirementUrl((string) $row['url']),
            $row['official_reference'] === null
                ? null
                : new AcknowledgementOfficialReference((string) $row['official_reference']),
            $status,
        );
    }

    private function sameState(
        AcknowledgementRequirement $persisted,
        AcknowledgementRequirement $requested,
    ): bool {
        $requestedId = $requested->id();
        $persistedId = $persisted->id();

        return ($requestedId === null || ($persistedId !== null && $persistedId->equals($requestedId)))
            && $persisted->academicPeriodId()->equals($requested->academicPeriodId())
            && $persisted->title()->equals($requested->title())
            && $persisted->url()->equals($requested->url())
            && $this->sameOfficialReference(
                $persisted->officialReference(),
                $requested->officialReference(),
            )
            && $persisted->status() === $requested->status();
    }

    private function sameOfficialReference(
        ?AcknowledgementOfficialReference $left,
        ?AcknowledgementOfficialReference $right,
    ): bool {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function generatedId(string $entity): int
    {
        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException($entity . ' insert did not produce a positive database identity.');
        }

        return $id;
    }

    private function selectSql(): string
    {
        return 'SELECT ar.id, ar.academic_period_id, ar.title, ar.url, ar.official_reference, '
            . 'ar.status_id, s.code AS status_code, st.code AS status_type_code '
            . 'FROM acknowledgement_requirements ar '
            . 'INNER JOIN statuses s ON s.id = ar.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    /**
     * @param array<string, int> $parameters
     * @return list<AcknowledgementRequirement>
     */
    private function lockedRows(string $where, array $parameters): array
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Acknowledgement Requirement locking requires an active transaction.');
        }

        $sql = 'SELECT ar.id, ar.academic_period_id, ar.title, ar.url, '
            . 'ar.official_reference, ar.status_id '
            . 'FROM acknowledgement_requirements ar' . $where;
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return array_map(
            fn (array $row): AcknowledgementRequirement => $this->mapLockedRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapLockedRow(array $row): AcknowledgementRequirement
    {
        $statement = $this->connection->prepare(
            'SELECT s.code AS status_code, st.code AS status_type_code '
            . 'FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE s.id = :statusId'
        );
        $statement->execute([':statusId' => (int) $row['status_id']]);
        $statusRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($statusRows) !== 1) {
            throw new RuntimeException('Acknowledgement Requirement status did not resolve exactly once.');
        }

        return $this->mapRow(array_merge($row, $statusRows[0]));
    }
}
