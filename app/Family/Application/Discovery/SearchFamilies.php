<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\Family\Application\Discovery\Dto\FamilyDiscoveryResult;
use RuntimeException;

final readonly class SearchFamilies
{
    private const VISIBLE_LIMIT = 30;

    public function __construct(
        private FamilyDiscoveryQuery $query,
        private GetActiveAcademicPeriod $getActiveAcademicPeriod,
    ) {
    }

    public function handle(FamilyDirectoryCriteria $criteria): FamilyDiscoveryResult
    {
        $activePeriod = $this->getActiveAcademicPeriod->handle();
        $rows = $this->query->search($criteria, $activePeriod?->id);
        if (count($rows) > self::VISIBLE_LIMIT + 1) {
            throw new RuntimeException('Family discovery query exceeded its bounded contract.');
        }

        return new FamilyDiscoveryResult(
            array_slice($rows, 0, self::VISIBLE_LIMIT),
            count($rows) > self::VISIBLE_LIMIT,
        );
    }
}
