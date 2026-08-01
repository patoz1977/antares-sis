<?php

declare(strict_types=1);

namespace App\Person\Infrastructure\Persistence;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PdoPersonRepository implements PersonRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(PersonId $id): ?Person
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE p.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findByIdentification(Identification $identification): ?Person
    {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE p.identification_key = :identificationKey'
            . ' LIMIT 1'
        );
        $statement->execute([
            ':identificationKey' => $this->identificationKey($identification),
        ]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function save(Person $person): Person
    {
        $statusId = $this->resolveStatusId($person->status());

        if ($person->id() === null) {
            return $this->insert($person, $statusId);
        }

        return $this->update($person, $statusId);
    }

    private function insert(Person $person, int $statusId): Person
    {
        $statement = $this->connection->prepare(
            'INSERT INTO persons ('
            . 'first_name, middle_name, first_surname, second_surname, '
            . 'document_type_id, document_number, birth_date, sex_id, '
            . 'marital_status_id, education_level_id, email, mobile_phone, '
            . 'landline_phone, status_id'
            . ') VALUES ('
            . ':firstName, :middleName, :firstSurname, :secondSurname, '
            . ':documentTypeId, :documentNumber, :birthDate, :sexId, '
            . ':maritalStatusId, :educationLevelId, :email, :mobilePhone, '
            . ':landlinePhone, :statusId'
            . ')'
        );
        $statement->execute($this->persistenceValues($person, $statusId));

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Person insert did not affect exactly one row.');
        }

        $generatedId = (int) $this->connection->lastInsertId();
        if ($generatedId <= 0) {
            throw new RuntimeException('Person insert did not produce a positive database identity.');
        }

        $persisted = $this->findById(new PersonId($generatedId));
        if ($persisted === null) {
            throw new RuntimeException('Inserted Person could not be reconstructed.');
        }

        return $persisted;
    }

    private function update(Person $person, int $statusId): Person
    {
        $id = $person->id();
        if ($id === null) {
            throw new RuntimeException('A Person without persisted identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE persons SET '
            . 'first_name = :firstName, middle_name = :middleName, '
            . 'first_surname = :firstSurname, second_surname = :secondSurname, '
            . 'document_type_id = :documentTypeId, document_number = :documentNumber, '
            . 'birth_date = :birthDate, sex_id = :sexId, '
            . 'marital_status_id = :maritalStatusId, education_level_id = :educationLevelId, '
            . 'email = :email, mobile_phone = :mobilePhone, landline_phone = :landlinePhone, '
            . 'status_id = :statusId WHERE id = :id'
        );
        $values = $this->persistenceValues($person, $statusId);
        $values[':id'] = $id->value();
        $statement->execute($values);

        $affectedRows = $statement->rowCount();
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException('Person update did not affect zero or one row.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('Person update failed because the persisted row disappeared.');
        }

        if ($affectedRows === 0 && !$this->sameState($persisted, $person)) {
            throw new RuntimeException('Person update did not persist the requested state.');
        }

        return $persisted;
    }

    private function resolveStatusId(PersonStatus $status): int
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
            throw new RuntimeException('Person status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, int|string|null> */
    private function persistenceValues(Person $person, int $statusId): array
    {
        $name = $person->personalName();
        $identification = $person->identification();
        $contact = $person->contactInformation();

        return [
            ':firstName' => $name->firstName(),
            ':middleName' => $name->middleName(),
            ':firstSurname' => $name->firstSurname(),
            ':secondSurname' => $name->secondSurname(),
            ':documentTypeId' => $identification?->documentTypeId(),
            ':documentNumber' => $identification?->documentNumber(),
            ':birthDate' => $person->birthDate()->format('Y-m-d'),
            ':sexId' => $person->sexId(),
            ':maritalStatusId' => $person->maritalStatusId(),
            ':educationLevelId' => $person->educationLevelId(),
            ':email' => $contact?->email(),
            ':mobilePhone' => $contact?->mobilePhone(),
            ':landlinePhone' => $contact?->landlinePhone(),
            ':statusId' => $statusId,
        ];
    }

    private function selectSql(): string
    {
        return 'SELECT p.id, p.first_name, p.middle_name, p.first_surname, '
            . 'p.second_surname, p.document_type_id, p.document_number, '
            . 'p.identification_key, p.birth_date, p.sex_id, p.marital_status_id, '
            . 'p.education_level_id, p.email, p.mobile_phone, p.landline_phone, '
            . 'p.status_id, p.created_at, p.updated_at, '
            . 's.code AS status_code, st.code AS status_type_code '
            . 'FROM persons p '
            . 'INNER JOIN statuses s ON s.id = p.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    private function identificationKey(Identification $identification): string
    {
        return sprintf(
            '%d:%s',
            $identification->documentTypeId(),
            $identification->documentNumber(),
        );
    }

    private function mapRow(array|false $row): ?Person
    {
        if ($row === false) {
            return null;
        }

        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('Person status does not belong to GENERAL_STATUS.');
        }

        $status = PersonStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('Person has an unsupported GENERAL_STATUS value.');
        }

        $birthDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            (string) $row['birth_date'],
            new DateTimeZone('UTC'),
        );
        if ($birthDate === false) {
            throw new RuntimeException('Person has an invalid persisted birth date.');
        }

        return new Person(
            new PersonId((int) $row['id']),
            new PersonalName(
                (string) $row['first_name'],
                $this->nullableString($row['middle_name']),
                (string) $row['first_surname'],
                $this->nullableString($row['second_surname']),
            ),
            $this->mapIdentification($row),
            $birthDate,
            (int) $row['sex_id'],
            $this->nullableInt($row['marital_status_id']),
            $this->nullableInt($row['education_level_id']),
            $this->mapContactInformation($row),
            $status,
            new DateTimeImmutable('today', new DateTimeZone('UTC')),
        );
    }

    private function mapIdentification(array $row): ?Identification
    {
        $documentTypeId = $this->nullableInt($row['document_type_id']);
        $documentNumber = $this->nullableString($row['document_number']);

        if ($documentTypeId === null && $documentNumber === null) {
            return null;
        }

        if ($documentTypeId === null || $documentNumber === null) {
            throw new RuntimeException('Person has an incomplete persisted identification.');
        }

        return new Identification($documentTypeId, $documentNumber);
    }

    private function mapContactInformation(array $row): ?ContactInformation
    {
        $email = $this->nullableString($row['email']);
        $mobilePhone = $this->nullableString($row['mobile_phone']);
        $landlinePhone = $this->nullableString($row['landline_phone']);

        if ($email === null && $mobilePhone === null && $landlinePhone === null) {
            return null;
        }

        return new ContactInformation($email, $mobilePhone, $landlinePhone);
    }

    private function sameState(Person $persisted, Person $person): bool
    {
        $persistedId = $persisted->id();
        $personId = $person->id();
        if ($persistedId === null || $personId === null || !$persistedId->equals($personId)) {
            return false;
        }

        return $persisted->personalName()->equals($person->personalName())
            && $this->sameIdentification($persisted->identification(), $person->identification())
            && $persisted->birthDate()->format('Y-m-d') === $person->birthDate()->format('Y-m-d')
            && $persisted->sexId() === $person->sexId()
            && $persisted->maritalStatusId() === $person->maritalStatusId()
            && $persisted->educationLevelId() === $person->educationLevelId()
            && $this->sameContact($persisted->contactInformation(), $person->contactInformation())
            && $persisted->status() === $person->status();
    }

    private function sameIdentification(?Identification $left, ?Identification $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function sameContact(?ContactInformation $left, ?ContactInformation $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
