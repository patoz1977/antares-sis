<?php

declare(strict_types=1);

namespace App\Family\Http;

interface FamilyFormOptionsProvider
{
    public function get(): FamilyFormOptions;
}
