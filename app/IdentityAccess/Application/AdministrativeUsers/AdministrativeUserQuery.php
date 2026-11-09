<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;

interface AdministrativeUserQuery
{
    /** @return list<AdministrativeUserRow> */
    public function search(?AdministrativeUserSearchCriteria $criteria, int $limit): array;

    public function findById(int $userId): ?AdministrativeUserRow;
}
