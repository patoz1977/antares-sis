<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\ValueObject\FamilyCode;

final class FamilyCodeTestFactory
{
    private static int $sequence = 1;

    public static function next(): FamilyCode
    {
        $code = new FamilyCode(sprintf('F%08d', self::$sequence));
        self::$sequence++;

        return $code;
    }
}
