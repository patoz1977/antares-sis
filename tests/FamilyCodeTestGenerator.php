<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\FamilyCodeGenerator;
use App\Family\Domain\ValueObject\FamilyCode;

final readonly class FamilyCodeTestGenerator implements FamilyCodeGenerator
{
    public function generate(): FamilyCode
    {
        return FamilyCodeTestFactory::next();
    }
}
