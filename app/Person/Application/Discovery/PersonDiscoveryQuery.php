<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery;

use App\Person\Application\Discovery\Dto\PersonDiscoveryRow;

interface PersonDiscoveryQuery
{
    /** @return list<PersonDiscoveryRow> Up to 31 ordered rows. */
    public function search(PersonDiscoveryCriteria $criteria): array;
}
