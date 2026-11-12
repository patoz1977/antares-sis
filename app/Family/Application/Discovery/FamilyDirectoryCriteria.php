<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

final readonly class FamilyDirectoryCriteria
{
    public function __construct(
        public FamilyDirectoryStatus $status,
        public ?FamilyDiscoveryCriteria $search = null,
    ) {
    }

    public static function active(): self
    {
        return new self(FamilyDirectoryStatus::Active);
    }
}
