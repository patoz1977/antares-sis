<?php

declare(strict_types=1);

namespace App\Family\Http;

final readonly class FamilyResourceFormOptions
{
    /**
     * @param list<FamilyResourceFormOption> $relationshipTypes
     * @param list<FamilyResourceFormOption> $documentTypes
     */
    public function __construct(
        public array $relationshipTypes,
        public array $documentTypes,
    ) {
    }

    public function hasRelationshipType(int $id): bool
    {
        return $this->contains($this->relationshipTypes, $id);
    }

    public function hasDocumentType(int $id): bool
    {
        return $this->contains($this->documentTypes, $id);
    }

    /** @param list<FamilyResourceFormOption> $options */
    private function contains(array $options, int $id): bool
    {
        foreach ($options as $option) {
            if ($option->id === $id) {
                return true;
            }
        }

        return false;
    }
}
