<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Infrastructure\Persistence;

use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgement;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementCompletionId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class PdoRepresentativeAcknowledgementCompletionRepository implements
    RepresentativeAcknowledgementCompletionRepository
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findByRepresentativeAndAcademicPeriod(
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
    ): ?RepresentativeAcknowledgementCompletion {
        $statement = $this->connection->prepare(
            'SELECT id, representative_id, academic_period_id, completed_at '
            . 'FROM representative_acknowledgement_completions '
            . 'WHERE representative_id = :representativeId '
            . 'AND academic_period_id = :academicPeriodId'
        );
        $statement->execute([
            ':representativeId' => $representativeId->value(),
            ':academicPeriodId' => $academicPeriodId->value(),
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 1) {
            throw new RuntimeException(
                'Representative and AcademicPeriod resolved more than one persisted completion.'
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->mapCompletion($rows[0], $representativeId, $academicPeriodId);
    }

    public function save(
        RepresentativeAcknowledgementCompletion $completion,
    ): RepresentativeAcknowledgementCompletion {
        if ($completion->id() !== null) {
            throw new RuntimeException('A persisted acknowledgement completion is immutable and cannot be saved again.');
        }

        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction && !$this->connection->beginTransaction()) {
            throw new RuntimeException('Acknowledgement completion persistence could not start its transaction.');
        }

        try {
            $this->insertCompletion($completion);
            $persisted = $this->findByRepresentativeAndAcademicPeriod(
                $completion->representativeId(),
                $completion->academicPeriodId(),
            );

            if ($persisted === null || !$this->sameState($persisted, $completion)) {
                throw new RuntimeException('Inserted acknowledgement completion could not be reconstructed exactly.');
            }

            if ($ownsTransaction && !$this->connection->commit()) {
                throw new RuntimeException('Acknowledgement completion persistence could not commit its transaction.');
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function insertCompletion(RepresentativeAcknowledgementCompletion $completion): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO representative_acknowledgement_completions ('
            . 'representative_id, academic_period_id, completed_at'
            . ') VALUES ('
            . ':representativeId, :academicPeriodId, :completedAt'
            . ')'
        );
        $statement->execute([
            ':representativeId' => $completion->representativeId()->value(),
            ':academicPeriodId' => $completion->academicPeriodId()->value(),
            ':completedAt' => $this->formatTimestamp($completion->completedAt()),
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Acknowledgement completion insert did not affect exactly one row.');
        }

        $completionId = $this->generatedId('Acknowledgement completion');
        foreach ($completion->acknowledgements() as $acknowledgement) {
            if ($acknowledgement->id() !== null) {
                throw new RuntimeException('A new completion cannot contain a persisted acknowledgement child.');
            }

            $this->insertAcknowledgement($completionId, $completion->academicPeriodId(), $acknowledgement);
        }
    }

    private function insertAcknowledgement(
        int $completionId,
        AcademicPeriodId $academicPeriodId,
        RepresentativeAcknowledgement $acknowledgement,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO representative_acknowledgements ('
            . 'representative_acknowledgement_completion_id, '
            . 'acknowledgement_requirement_id, academic_period_id'
            . ') VALUES ('
            . ':completionId, :requirementId, :academicPeriodId'
            . ')'
        );
        $statement->execute([
            ':completionId' => $completionId,
            ':requirementId' => $acknowledgement->acknowledgementRequirementId()->value(),
            ':academicPeriodId' => $academicPeriodId->value(),
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Representative acknowledgement insert did not affect exactly one row.');
        }

        $this->generatedId('Representative acknowledgement');
    }

    /** @param array<string, mixed> $row */
    private function mapCompletion(
        array $row,
        RepresentativeId $requestedRepresentativeId,
        AcademicPeriodId $requestedAcademicPeriodId,
    ): RepresentativeAcknowledgementCompletion {
        $representativeId = new RepresentativeId((int) $row['representative_id']);
        $academicPeriodId = new AcademicPeriodId((int) $row['academic_period_id']);
        if (!$representativeId->equals($requestedRepresentativeId)
            || !$academicPeriodId->equals($requestedAcademicPeriodId)
        ) {
            throw new RuntimeException('Persisted completion does not match the requested ownership.');
        }

        $completionId = new RepresentativeAcknowledgementCompletionId((int) $row['id']);
        $acknowledgements = $this->findAcknowledgements($completionId);
        if ($acknowledgements === []) {
            throw new RuntimeException('Persisted acknowledgement completion has no children.');
        }

        return RepresentativeAcknowledgementCompletion::reconstitute(
            $completionId,
            $representativeId,
            $academicPeriodId,
            $this->parseTimestamp((string) $row['completed_at']),
            $acknowledgements,
        );
    }

    /** @return list<RepresentativeAcknowledgement> */
    private function findAcknowledgements(
        RepresentativeAcknowledgementCompletionId $completionId,
    ): array {
        $statement = $this->connection->prepare(
            'SELECT id, acknowledgement_requirement_id '
            . 'FROM representative_acknowledgements '
            . 'WHERE representative_acknowledgement_completion_id = :completionId '
            . 'ORDER BY id ASC'
        );
        $statement->execute([':completionId' => $completionId->value()]);

        return array_map(
            static fn (array $row): RepresentativeAcknowledgement =>
                RepresentativeAcknowledgement::reconstitute(
                    new RepresentativeAcknowledgementId((int) $row['id']),
                    new AcknowledgementRequirementId((int) $row['acknowledgement_requirement_id']),
                ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function sameState(
        RepresentativeAcknowledgementCompletion $persisted,
        RepresentativeAcknowledgementCompletion $requested,
    ): bool {
        if (!$persisted->representativeId()->equals($requested->representativeId())
            || !$persisted->academicPeriodId()->equals($requested->academicPeriodId())
            || $this->formatTimestamp($persisted->completedAt())
                !== $this->formatTimestamp($requested->completedAt())
        ) {
            return false;
        }

        $persistedRequirements = array_map(
            static fn (RepresentativeAcknowledgement $acknowledgement): int =>
                $acknowledgement->acknowledgementRequirementId()->value(),
            $persisted->acknowledgements(),
        );
        $requestedRequirements = array_map(
            static fn (RepresentativeAcknowledgement $acknowledgement): int =>
                $acknowledgement->acknowledgementRequirementId()->value(),
            $requested->acknowledgements(),
        );
        sort($persistedRequirements, SORT_NUMERIC);
        sort($requestedRequirements, SORT_NUMERIC);

        return $persistedRequirements === $requestedRequirements;
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseTimestamp(string $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('Completion completed_at has an invalid persisted UTC timestamp.');
        }

        return $date;
    }

    private function generatedId(string $entity): int
    {
        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException($entity . ' insert did not produce a positive database identity.');
        }

        return $id;
    }
}
