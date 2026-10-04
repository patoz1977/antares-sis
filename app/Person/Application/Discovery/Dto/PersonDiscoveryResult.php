<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery\Dto;

final readonly class PersonDiscoveryResult
{
    /** @param list<PersonDiscoveryRow> $rows */
    public function __construct(public array $rows, public bool $hasMore)
    {
    }
}
