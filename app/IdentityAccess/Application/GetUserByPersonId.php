<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Dto\RepresentativeUserOutput;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\PersonId;

final readonly class GetUserByPersonId
{
    public function __construct(private UserRepository $users)
    {
    }

    public function handle(int $personId): ?RepresentativeUserOutput
    {
        $id = new PersonId($personId);
        $user = $this->users->findByPersonId($id);

        return $user === null
            ? null
            : RepresentativeUserOutput::fromUser($user, expectedPersonId: $id);
    }
}
