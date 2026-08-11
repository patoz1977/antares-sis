<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class PickupIdentification
{
    private string $documentNumber;

    public function __construct(private DocumentTypeId $documentTypeId, string $documentNumber)
    {
        $normalized = trim($documentNumber);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 50) {
            throw new InvalidFamilyState('Document number must contain between 1 and 50 characters.');
        }

        $this->documentNumber = $normalized;
    }

    public static function fromPair(?DocumentTypeId $documentTypeId, ?string $documentNumber): ?self
    {
        $normalizedNumber = $documentNumber === null || trim($documentNumber) === ''
            ? null
            : $documentNumber;

        if ($documentTypeId === null && $normalizedNumber === null) {
            return null;
        }

        if ($documentTypeId === null || $normalizedNumber === null) {
            throw new InvalidFamilyState('Document type and document number must both be present or absent.');
        }

        return new self($documentTypeId, $normalizedNumber);
    }

    public function documentTypeId(): DocumentTypeId
    {
        return $this->documentTypeId;
    }

    public function documentNumber(): string
    {
        return $this->documentNumber;
    }

    public function equals(self $other): bool
    {
        return $this->documentTypeId->equals($other->documentTypeId)
            && $this->documentNumber === $other->documentNumber;
    }
}
