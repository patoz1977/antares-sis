<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Persistence;

use App\Enrollment\Application\Administrative\SubmittedEnrollmentIdQuery;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final readonly class PdoSubmittedEnrollmentIdQuery implements SubmittedEnrollmentIdQuery
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findSubmittedEnrollmentIds(): array
    {
        $statement = $this->connection->prepare(
            'SELECT e.id, e.submitted_at FROM enrollments e '
            . 'INNER JOIN statuses status_row ON status_row.id = e.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
            . 'WHERE status_type.code = :statusType AND status_row.code = :statusCode '
            . 'ORDER BY e.submitted_at DESC, e.id DESC'
        );
        $statement->execute([
            ':statusType' => 'ENROLLMENT_STATUS',
            ':statusCode' => 'SUBMITTED',
        ]);

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $this->positiveInt($row['id'] ?? null);
            $this->requireUtcTimestamp($row['submitted_at'] ?? null);
            if (isset($ids[$id])) {
                throw new RuntimeException('Submitted Enrollment query returned a duplicate identity.');
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $id = (int) $value;
        } else {
            throw new RuntimeException('Submitted Enrollment query returned an invalid identity.');
        }

        if ($id <= 0) {
            throw new RuntimeException('Submitted Enrollment query returned an invalid identity.');
        }

        return $id;
    }

    private function requireUtcTimestamp(mixed $value): void
    {
        if (!is_string($value)) {
            throw new RuntimeException('Submitted Enrollment query returned an invalid submitted_at value.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('Submitted Enrollment query returned an invalid submitted_at value.');
        }
    }
}
