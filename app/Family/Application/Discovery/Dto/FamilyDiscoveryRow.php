<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery\Dto;

final readonly class FamilyDiscoveryRow
{
    /** @param list<FamilyMatchedMember> $matchedMembers */
    public function __construct(
        public int $familyId,
        public string $familyCode,
        public string $displayName,
        public string $status,
        public array $matchedMembers,
    ) {
    }
}
