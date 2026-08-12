<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class ActivateFamilyAddressInput
{
    public function __construct(public int $familyId, public int $familyAddressId)
    {
    }
}
