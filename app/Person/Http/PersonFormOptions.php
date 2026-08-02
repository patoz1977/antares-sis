<?php

declare(strict_types=1);

namespace App\Person\Http;

final readonly class PersonFormOptions
{
    /**
     * @param list<PersonFormOption> $documentTypes
     * @param list<PersonFormOption> $sexes
     * @param list<PersonFormOption> $maritalStatuses
     * @param list<PersonFormOption> $educationLevels
     * @param list<PersonFormOption> $statuses
     */
    public function __construct(
        public array $documentTypes,
        public array $sexes,
        public array $maritalStatuses,
        public array $educationLevels,
        public array $statuses,
    ) {
    }

    public function isReadyForSave(): bool
    {
        return $this->sexes !== []
            && $this->hasStatus('ACTIVE')
            && $this->hasStatus('INACTIVE');
    }

    public function hasDocumentType(int $id): bool
    {
        return $this->containsId($this->documentTypes, $id);
    }

    public function hasSex(int $id): bool
    {
        return $this->containsId($this->sexes, $id);
    }

    public function hasMaritalStatus(int $id): bool
    {
        return $this->containsId($this->maritalStatuses, $id);
    }

    public function hasEducationLevel(int $id): bool
    {
        return $this->containsId($this->educationLevels, $id);
    }

    public function hasStatus(string $code): bool
    {
        foreach ($this->statuses as $option) {
            if ($option->code === $code) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PersonFormOption> $options */
    private function containsId(array $options, int $id): bool
    {
        foreach ($options as $option) {
            if ($option->id === $id) {
                return true;
            }
        }

        return false;
    }
}
