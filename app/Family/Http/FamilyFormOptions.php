<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Domain\FamilyStatus;

final readonly class FamilyFormOptions
{
    /**
     * @param list<FamilyFormOption> $relationshipTypes
     * @param list<FamilyStatus> $statuses
     */
    public function __construct(
        public array $relationshipTypes,
        public array $statuses,
    ) {
    }

    public function isReadyForSave(): bool
    {
        return $this->relationshipTypes !== []
            && $this->hasStatus(FamilyStatus::Active)
            && $this->hasStatus(FamilyStatus::Inactive);
    }

    public function hasRelationshipType(int $id): bool
    {
        foreach ($this->relationshipTypes as $option) {
            if ($option->id === $id) {
                return true;
            }
        }

        return false;
    }

    public function hasStatus(FamilyStatus $status): bool
    {
        return in_array($status, $this->statuses, true);
    }
}
