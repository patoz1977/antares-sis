<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserQuery;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserSearchCriteria;
use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;

final class InMemoryAdministrativeUserQuery implements AdministrativeUserQuery
{
    public ?AdministrativeUserSearchCriteria $lastCriteria = null;
    public int $lastLimit = 0;

    /** @param list<AdministrativeUserRow> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function search(?AdministrativeUserSearchCriteria $criteria, int $limit): array
    {
        $this->lastCriteria = $criteria;
        $this->lastLimit = $limit;

        return array_slice($this->rows, 0, $limit);
    }

    public function findById(int $userId): ?AdministrativeUserRow
    {
        foreach ($this->rows as $row) {
            if ($row->userId === $userId) {
                return $row;
            }
        }

        return null;
    }
}
