<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\RelationshipTypeLookup;

final readonly class FakeRelationshipTypeLookup implements RelationshipTypeLookup
{
    /** @param list<int> $existingIds */
    public function __construct(private array $existingIds)
    {
    }

    public function exists(int $relationshipTypeId): bool
    {
        return in_array($relationshipTypeId, $this->existingIds, true);
    }
}
