<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserAccessType;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserQuery;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserSearchCriteria;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserSearchField;
use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;
use App\IdentityAccess\Domain\UserStatus;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final readonly class PdoAdministrativeUserQuery implements AdministrativeUserQuery
{
    private const REPORTS_CAPABILITY = 'REPORTS_VIEW_ALL';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function search(?AdministrativeUserSearchCriteria $criteria, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Administrative User query limit is invalid.');
        }

        [$predicate, $parameters] = $this->predicate($criteria);
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ($predicate === '' ? '' : ' WHERE ' . $predicate)
            . ' ORDER BY p.first_surname, p.second_surname, p.first_name, p.middle_name, u.id'
            . ' LIMIT ' . $limit
        );
        $statement->execute($parameters);

        return $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $userId): ?AdministrativeUserRow
    {
        if ($userId <= 0) {
            return null;
        }

        $statement = $this->connection->prepare($this->selectSql() . ' WHERE u.id = :userId LIMIT 1');
        $statement->execute([':userId' => $userId]);
        $rows = $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC));

        return $rows[0] ?? null;
    }

    private function selectSql(): string
    {
        return 'SELECT u.id AS user_id, u.person_id, u.login_identifier, u.last_access_at, '
            . 'p.first_name, p.middle_name, p.first_surname, p.second_surname, p.document_number, '
            . 's.code AS user_status, st.code AS status_type, r.id AS representative_id, '
            . 'student.id AS student_id, '
            . 'EXISTS (SELECT 1 FROM user_capabilities capability '
            . 'WHERE capability.user_id = u.id AND BINARY capability.capability_code = BINARY :capability) '
            . 'AS has_reports_capability '
            . 'FROM users u '
            . 'INNER JOIN persons p ON p.id = u.person_id '
            . 'INNER JOIN statuses s ON s.id = u.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'LEFT JOIN representatives r ON r.person_id = p.id '
            . 'LEFT JOIN students student ON student.person_id = p.id';
    }

    /** @return array{string, array<string, int|string>} */
    private function predicate(?AdministrativeUserSearchCriteria $criteria): array
    {
        $parameters = [':capability' => self::REPORTS_CAPABILITY];
        if ($criteria === null) {
            return ['', $parameters];
        }

        if ($criteria->field === AdministrativeUserSearchField::Name) {
            $tokens = preg_split('/\s+/u', $criteria->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokenPredicates = [];
            foreach ($tokens as $tokenIndex => $token) {
                $columnPredicates = [];
                foreach (['first_name', 'middle_name', 'first_surname', 'second_surname'] as $columnIndex => $column) {
                    $parameter = ':name' . $tokenIndex . '_' . $columnIndex;
                    $parameters[$parameter] = $this->escapeLike($token) . '%';
                    $columnPredicates[] = "p.{$column} LIKE {$parameter} ESCAPE '!'";
                }
                $tokenPredicates[] = '(' . implode(' OR ', $columnPredicates) . ')';
            }

            return ['(' . implode(' AND ', $tokenPredicates) . ')', $parameters];
        }

        if ($criteria->field === AdministrativeUserSearchField::Identification) {
            $parameters[':identification'] = mb_strtoupper($criteria->value, 'UTF-8');

            return ['UPPER(TRIM(p.document_number)) = :identification', $parameters];
        }

        $parameters[':loginIdentifier'] = $criteria->value;

        return ['u.normalized_login_identifier = :loginIdentifier', $parameters];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<AdministrativeUserRow>
     */
    private function mapRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (($row['status_type'] ?? null) !== 'USER_STATUS') {
                throw new RuntimeException('Administrative User query returned an invalid status type.');
            }
            $status = UserStatus::tryFrom((string) ($row['user_status'] ?? ''));
            if ($status === null) {
                throw new RuntimeException('Administrative User query returned an unsupported status.');
            }

            $representativeId = $this->nullablePositiveInt($row['representative_id'] ?? null);
            $studentId = $this->nullablePositiveInt($row['student_id'] ?? null);
            $loginIdentifier = $this->requiredString($row['login_identifier'] ?? null);
            $hasReports = (int) ($row['has_reports_capability'] ?? 0) === 1;
            $accessType = $this->accessType($loginIdentifier, $representativeId, $studentId, $hasReports);

            $result[] = new AdministrativeUserRow(
                $this->positiveInt($row['user_id'] ?? null),
                $this->positiveInt($row['person_id'] ?? null),
                $this->fullName($row),
                $this->nullableString($row['document_number'] ?? null),
                $loginIdentifier,
                $status,
                $this->nullableString($row['last_access_at'] ?? null),
                $accessType,
                $representativeId,
            );
        }

        return $result;
    }

    private function accessType(
        string $loginIdentifier,
        ?int $representativeId,
        ?int $studentId,
        bool $hasReports,
    ): AdministrativeUserAccessType {
        if ($loginIdentifier === 'admin') {
            return AdministrativeUserAccessType::Administrator;
        }
        if ($representativeId !== null && $hasReports) {
            return AdministrativeUserAccessType::Incoherent;
        }
        if ($representativeId !== null) {
            return AdministrativeUserAccessType::Representative;
        }
        if ($studentId !== null && $hasReports) {
            return AdministrativeUserAccessType::Incoherent;
        }
        if ($hasReports) {
            return AdministrativeUserAccessType::InstitutionalReporting;
        }

        return AdministrativeUserAccessType::Unsupported;
    }

    /** @param array<string, mixed> $row */
    private function fullName(array $row): string
    {
        $parts = [];
        foreach (['first_name', 'middle_name', 'first_surname', 'second_surname'] as $column) {
            $value = $this->nullableString($row[$column] ?? null);
            if ($value !== null && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }
        if ($parts === []) {
            throw new RuntimeException('Administrative User query returned an invalid Person name.');
        }

        return implode(' ', $parts);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function positiveInt(mixed $value): int
    {
        $parsed = $this->nullablePositiveInt($value);
        if ($parsed === null) {
            throw new RuntimeException('Administrative User query returned an invalid identity.');
        }

        return $parsed;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function requiredString(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Administrative User query returned invalid text.');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Administrative User query returned invalid optional text.');
        }

        return $value;
    }
}
