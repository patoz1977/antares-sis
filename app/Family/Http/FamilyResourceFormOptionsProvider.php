<?php

declare(strict_types=1);

namespace App\Family\Http;

interface FamilyResourceFormOptionsProvider
{
    public function get(): FamilyResourceFormOptions;
}
