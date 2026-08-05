<?php

declare(strict_types=1);

namespace App\Family\Application;

interface RelationshipTypeLookup
{
    public function exists(int $relationshipTypeId): bool;
}
