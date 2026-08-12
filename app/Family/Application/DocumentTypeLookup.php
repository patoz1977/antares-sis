<?php

declare(strict_types=1);

namespace App\Family\Application;

interface DocumentTypeLookup
{
    public function exists(int $documentTypeId): bool;
}
