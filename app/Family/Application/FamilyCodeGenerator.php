<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Domain\ValueObject\FamilyCode;

interface FamilyCodeGenerator
{
    public function generate(): FamilyCode;
}
