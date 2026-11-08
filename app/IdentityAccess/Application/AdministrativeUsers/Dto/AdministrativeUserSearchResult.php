<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers\Dto;

final readonly class AdministrativeUserSearchResult
{
    /** @param list<AdministrativeUserRow> $rows */
    public function __construct(public array $rows, public bool $hasMore)
    {
    }
}
