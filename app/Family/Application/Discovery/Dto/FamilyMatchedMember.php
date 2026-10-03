<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery\Dto;

final readonly class FamilyMatchedMember
{
    public function __construct(
        public string $fullName,
        public string $role,
    ) {
    }
}
