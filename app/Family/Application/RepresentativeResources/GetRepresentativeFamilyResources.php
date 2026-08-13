<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyResourcesOutput;

final readonly class GetRepresentativeFamilyResources
{
    public function __construct(private RepresentativeFamilyResourceAuthorization $authorization)
    {
    }

    public function handle(): RepresentativeFamilyResourcesOutput
    {
        return $this->authorization->resolve()->portalResources;
    }
}
