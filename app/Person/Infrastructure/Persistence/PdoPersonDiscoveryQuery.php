<?php

declare(strict_types=1);

namespace App\Person\Infrastructure\Persistence;

use App\Person\Application\Discovery\Dto\PersonDiscoveryRow;
use App\Person\Application\Discovery\PersonDiscoveryCriteria;
use App\Person\Application\Discovery\PersonDiscoveryField;
use App\Person\Application\Discovery\PersonDiscoveryQuery;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final readonly class PdoPersonDiscoveryQuery implements PersonDiscoveryQuery
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function search(PersonDiscoveryCriteria $criteria): array
    {
        [$predicate, $parameters] = self::predicate($criteria, 'p', 'person');
        $statement = $this->connection->prepare(
            'SELECT p.id, p.first_name, p.middle_name, p.first_surname, p.second_surname, '
            . 'p.document_number, p.email FROM persons p WHERE ' . $predicate . ' '
            . 'ORDER BY p.first_surname, p.second_surname, p.first_name, p.middle_name, p.id LIMIT 31'
        );
        $statement->execute($parameters);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = new PersonDiscoveryRow(
                $this->positiveInt($row['id'] ?? null),
                $this->requiredString($row['first_name'] ?? null),
                $this->nullableString($row['middle_name'] ?? null),
                $this->requiredString($row['first_surname'] ?? null),
                $this->nullableString($row['second_surname'] ?? null),
                $this->nullableString($row['document_number'] ?? null),
                $this->nullableString($row['email'] ?? null),
            );
        }

        return $result;
    }

    /** @return array{string, array<string, string>} */
    private static function predicate(PersonDiscoveryCriteria $criteria, string $alias, string $parameterPrefix): array
    {
        $column = match ($criteria->field) {
            PersonDiscoveryField::FirstName => 'first_name',
            PersonDiscoveryField::MiddleName => 'middle_name',
            PersonDiscoveryField::FirstSurname => 'first_surname',
            PersonDiscoveryField::SecondSurname => 'second_surname',
            PersonDiscoveryField::IdentificationNumber => 'document_number',
            PersonDiscoveryField::PersonalEmail => 'email',
        };
        $parameter = ':' . $parameterPrefix . 'Exact';
        if ($criteria->field->isName()) {
            return [
                "({$alias}.{$column} LIKE :{$parameterPrefix}Start ESCAPE '!' "
                . "OR {$alias}.{$column} LIKE :{$parameterPrefix}Word ESCAPE '!')",
                [
                    ':' . $parameterPrefix . 'Start' => self::escapeLike($criteria->value) . '%',
                    ':' . $parameterPrefix . 'Word' => '% ' . self::escapeLike($criteria->value) . '%',
                ],
            ];
        }
        if ($criteria->field === PersonDiscoveryField::IdentificationNumber) {
            return ["UPPER(TRIM({$alias}.{$column})) = {$parameter}", [$parameter => mb_strtoupper($criteria->value, 'UTF-8')]];
        }

        return ["TRIM({$alias}.{$column}) = {$parameter}", [$parameter => $criteria->value]];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function positiveInt(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException('Person discovery returned an invalid identity.');
        }

        return $value;
    }

    private function requiredString(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Person discovery returned invalid identity data.');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Person discovery returned invalid optional data.');
        }

        return $value;
    }
}
