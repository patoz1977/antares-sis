<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class GetFamilyResources
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(int $familyId): FamilyResourcesOutput
    {
        $id = new FamilyId($familyId);

        return FamilyResourcesOutput::fromFamily(
            FamilyResourcesApplicationSupport::load($this->families, $id),
            $id,
        );
    }
}
