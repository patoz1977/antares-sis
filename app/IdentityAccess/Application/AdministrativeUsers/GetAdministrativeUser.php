<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserNotFound;

final readonly class GetAdministrativeUser
{
    public function __construct(private AdministrativeUserQuery $query)
    {
    }

    public function handle(int $userId): AdministrativeUserRow
    {
        if ($userId <= 0 || ($user = $this->query->findById($userId)) === null) {
            throw new AdministrativeUserNotFound('User was not found.');
        }

        return $user;
    }
}
