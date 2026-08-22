<?php

declare(strict_types=1);

namespace Tests;

use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use RuntimeException;

final class InMemoryRepresentativeApplicationRepository implements RepresentativeRepository
{
    /** @var array<int, Representative> */
    private array $representatives = [];

    private int $saveCalls = 0;

    private bool $returnWithoutId = false;

    public function __construct(private int $nextId = 700)
    {
    }

    public function seed(Representative $representative): void
    {
        $id = $representative->id();
        if ($id === null) {
            throw new RuntimeException('Seeded Representative must have an identity.');
        }

        $this->representatives[$id->value()] = clone $representative;
        $this->nextId = max($this->nextId, $id->value() + 1);
    }

    public function findById(RepresentativeId $id): ?Representative
    {
        return isset($this->representatives[$id->value()])
            ? clone $this->representatives[$id->value()]
            : null;
    }

    public function findByIdForUpdate(RepresentativeId $id): ?Representative
    {
        return $this->findById($id);
    }

    public function findByPersonId(PersonId $personId): ?Representative
    {
        foreach ($this->representatives as $representative) {
            if ($representative->personId()->equals($personId)) {
                return clone $representative;
            }
        }

        return null;
    }

    public function save(Representative $representative): Representative
    {
        $this->saveCalls++;
        if ($this->returnWithoutId) {
            return new Representative(
                null,
                $representative->personId(),
                $representative->employmentInformation(),
                $representative->status(),
            );
        }

        $persisted = $representative->id() === null
            ? new Representative(
                new RepresentativeId($this->nextId++),
                $representative->personId(),
                $representative->employmentInformation(),
                $representative->status(),
            )
            : clone $representative;
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Persisted Representative must have an identity.');
        }

        $this->representatives[$id->value()] = clone $persisted;

        return clone $persisted;
    }

    public function saveCalls(): int
    {
        return $this->saveCalls;
    }

    public function returnWithoutId(): void
    {
        $this->returnWithoutId = true;
    }
}
