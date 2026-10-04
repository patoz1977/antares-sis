<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery;

use App\Person\Application\Discovery\Dto\PersonDiscoveryResult;
use RuntimeException;

final readonly class SearchPersons
{
    private const VISIBLE_LIMIT = 30;

    public function __construct(private PersonDiscoveryQuery $query)
    {
    }

    public function handle(PersonDiscoveryCriteria $criteria): PersonDiscoveryResult
    {
        $rows = $this->query->search($criteria);
        if (count($rows) > self::VISIBLE_LIMIT + 1) {
            throw new RuntimeException('Person discovery query exceeded its bounded contract.');
        }

        return new PersonDiscoveryResult(
            array_slice($rows, 0, self::VISIBLE_LIMIT),
            count($rows) > self::VISIBLE_LIMIT,
        );
    }
}
