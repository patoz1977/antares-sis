<?php

declare(strict_types=1);

namespace App\Person\Application;

use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;

final readonly class GetPerson
{
    public function __construct(private PersonRepository $persons)
    {
    }

    public function handle(int $personId): PersonOutput
    {
        $id = new PersonId($personId);
        $person = $this->persons->findById($id);
        if ($person === null) {
            throw new PersonNotFound('Person was not found.');
        }

        return PersonOutput::fromPerson($person, $id);
    }
}
