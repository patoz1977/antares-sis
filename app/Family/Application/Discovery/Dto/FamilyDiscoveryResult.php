<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery\Dto;

final readonly class FamilyDiscoveryResult
{
    /** @param list<FamilyDiscoveryRow> $rows */
    public function __construct(public array $rows, public bool $hasMore)
    {
    }
}
