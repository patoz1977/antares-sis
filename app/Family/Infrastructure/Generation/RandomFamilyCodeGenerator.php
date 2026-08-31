<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Generation;

use App\Family\Application\FamilyCodeGenerator;
use App\Family\Domain\ValueObject\FamilyCode;

final readonly class RandomFamilyCodeGenerator implements FamilyCodeGenerator
{
    public function generate(): FamilyCode
    {
        return new FamilyCode(sprintf('F%08d', random_int(0, 99_999_999)));
    }
}
