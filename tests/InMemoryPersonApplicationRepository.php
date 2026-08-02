<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;
use RuntimeException;

final class InMemoryPersonApplicationRepository implements PersonRepository
{
    /** @var array<int, Person> */
    private array $persons = [];

    private int $saveCalls = 0;

    private ?Person $lastSaved = null;

    public function __construct(
        private readonly DateTimeImmutable $today,
        private int $nextId = 100,
    ) {
    }

    public function seed(Person $person): void
    {
        $id = $person->id();
        if ($id === null) {
            throw new RuntimeException('Seeded Person must have an identity.');
        }

        $this->persons[$id->value()] = clone $person;
        $this->nextId = max($this->nextId, $id->value() + 1);
    }

    public function findById(PersonId $id): ?Person
    {
        return isset($this->persons[$id->value()]) ? clone $this->persons[$id->value()] : null;
    }

    public function findByIdentification(Identification $identification): ?Person
    {
        $expectedKey = $this->identificationKey($identification);
        foreach ($this->persons as $person) {
            $storedIdentification = $person->identification();
            if ($storedIdentification !== null && $this->identificationKey($storedIdentification) === $expectedKey) {
                return clone $person;
            }
        }

        return null;
    }

    public function save(Person $person): Person
    {
        $this->saveCalls++;
        $persisted = $person->id() === null
            ? $this->copyWithId($person, new PersonId($this->nextId++))
            : clone $person;
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Persisted Person must have an identity.');
        }

        $this->persons[$id->value()] = clone $persisted;
        $this->lastSaved = clone $persisted;

        return clone $persisted;
    }

    public function saveCalls(): int
    {
        return $this->saveCalls;
    }

    public function lastSaved(): ?Person
    {
        return $this->lastSaved === null ? null : clone $this->lastSaved;
    }

    private function copyWithId(Person $person, PersonId $id): Person
    {
        return new Person(
            $id,
            $person->personalName(),
            $person->identification(),
            $person->birthDate(),
            $person->sexId(),
            $person->maritalStatusId(),
            $person->educationLevelId(),
            $person->contactInformation(),
            $person->status(),
            $this->today,
        );
    }

    private function identificationKey(Identification $identification): string
    {
        return sprintf(
            '%d:%s',
            $identification->documentTypeId(),
            mb_strtoupper($identification->documentNumber(), 'UTF-8'),
        );
    }
}
