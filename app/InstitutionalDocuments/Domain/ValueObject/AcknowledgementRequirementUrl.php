<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain\ValueObject;

use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;

final readonly class AcknowledgementRequirementUrl
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement requirement URL is required.'
            );
        }

        if (mb_strlen($normalized, 'UTF-8') > 500) {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement requirement URL cannot exceed 500 characters.'
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
