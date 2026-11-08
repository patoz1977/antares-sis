<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserSearchResult;
use RuntimeException;

final readonly class SearchAdministrativeUsers
{
    private const VISIBLE_LIMIT = 30;

    public function __construct(private AdministrativeUserQuery $query)
    {
    }

    public function handle(?AdministrativeUserSearchCriteria $criteria = null): AdministrativeUserSearchResult
    {
        $rows = $this->query->search($criteria, self::VISIBLE_LIMIT + 1);
        if (count($rows) > self::VISIBLE_LIMIT + 1) {
            throw new RuntimeException('Administrative User query exceeded its bounded contract.');
        }

        return new AdministrativeUserSearchResult(
            array_slice($rows, 0, self::VISIBLE_LIMIT),
            count($rows) > self::VISIBLE_LIMIT,
        );
    }
}
