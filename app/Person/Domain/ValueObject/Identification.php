<?php

declare(strict_types=1);

namespace App\Person\Domain\ValueObject;

use App\Person\Domain\Exception\InvalidPersonState;

final readonly class Identification
{
    private string $documentNumber;

    public function __construct(
        private int $documentTypeId,
        string $documentNumber,
    ) {
        if ($this->documentTypeId <= 0) {
            throw new InvalidPersonState('DocumentTypeId must be positive.');
        }

        $normalized = trim($documentNumber);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 50) {
            throw new InvalidPersonState('Document number must contain between 1 and 50 characters.');
        }

        $this->documentNumber = $normalized;
    }

    public function documentTypeId(): int
    {
        return $this->documentTypeId;
    }

    public function documentNumber(): string
    {
        return $this->documentNumber;
    }

    public function equals(self $other): bool
    {
        return $this->documentTypeId === $other->documentTypeId
            && $this->documentNumber === $other->documentNumber;
    }
}
