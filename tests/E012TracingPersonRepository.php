<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId;

final readonly class E012TracingPersonRepository implements PersonRepository
{
    public function __construct(
        private PersonRepository $delegate,
        private E012SubmissionTrace $trace,
    ) {
    }

    public function findById(PersonId $id): ?Person
    {
        return $this->delegate->findById($id);
    }

    public function findByIdForUpdate(PersonId $id): ?Person
    {
        $this->trace->events[] = 'person-lock:' . $id->value();

        return $this->delegate->findByIdForUpdate($id);
    }

    public function findByIdentification(Identification $identification): ?Person
    {
        return $this->delegate->findByIdentification($identification);
    }

    public function save(Person $person): Person
    {
        return $this->delegate->save($person);
    }
}
