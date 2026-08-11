<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class ActivateFamilyAuthorizedPickupInput
{
    public function __construct(public int $familyId, public int $familyAuthorizedPickupId)
    {
    }
}
