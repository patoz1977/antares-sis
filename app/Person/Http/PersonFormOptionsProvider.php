<?php

declare(strict_types=1);

namespace App\Person\Http;

interface PersonFormOptionsProvider
{
    public function get(): PersonFormOptions;
}
