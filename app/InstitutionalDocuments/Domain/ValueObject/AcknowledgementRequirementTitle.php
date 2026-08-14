<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain\ValueObject;

use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;

final readonly class AcknowledgementRequirementTitle
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement requirement title is required.'
            );
        }

        if (mb_strlen($normalized, 'UTF-8') > 200) {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement requirement title cannot exceed 200 characters.'
            );
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
