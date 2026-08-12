<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\DocumentTypeLookup;

final readonly class FakeFamilyResourceDocumentTypeLookup implements DocumentTypeLookup
{
    /** @param list<int> $existingIds */
    public function __construct(private array $existingIds)
    {
    }

    public function exists(int $documentTypeId): bool
    {
        return in_array($documentTypeId, $this->existingIds, true);
    }
}
