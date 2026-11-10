<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

use App\Family\Application\Discovery\Dto\FamilyDiscoveryRow;

interface FamilyDiscoveryQuery
{
    /** @return list<FamilyDiscoveryRow> Up to 31 ordered rows. */
    public function search(FamilyDirectoryCriteria $criteria, int $academicPeriodId): array;
}
