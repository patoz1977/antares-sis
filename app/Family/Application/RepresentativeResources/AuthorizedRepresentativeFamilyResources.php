<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyResourcesOutput;

final readonly class AuthorizedRepresentativeFamilyResources
{
    public function __construct(
        public int $representativeId,
        public FamilyResourcesOutput $completeResources,
        public RepresentativeFamilyResourcesOutput $portalResources,
    ) {
    }
}
