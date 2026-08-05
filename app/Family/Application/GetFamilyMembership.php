<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId;

final readonly class GetFamilyMembership
{
    public function __construct(private FamilyRepository $families)
    {
    }

    public function handle(int $familyId): FamilyOutput
    {
        $id = new FamilyId($familyId);
        $family = $this->families->findById($id);
        if ($family === null) {
            throw new FamilyNotFound('Family was not found.');
        }

        return FamilyOutput::fromFamily($family, $id);
    }
}
