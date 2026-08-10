<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class FamilyContext
{
    public function __construct(
        public int $userId,
        public int $personId,
        public int $representativeId,
        public int $familyId,
        public string $familyDisplayName,
    ) {
    }

    public static function from(
        AuthenticatedRepresentative $representative,
        AuthorizedFamily $family,
    ): self {
        return new self(
            $representative->userId,
            $representative->personId,
            $representative->representativeId,
            $family->familyId,
            $family->displayName,
        );
    }
}
