<?php

declare(strict_types=1);

namespace App\Family\Http;

interface FamilyMemberLabelsProvider
{
    public function forFamily(int $familyId): FamilyMemberLabels;
}
