<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

use App\Family\Domain\ValueObject\FamilyCode;
use DateTimeImmutable;

final readonly class RepresentativeWorkbookRow
{
    public function __construct(
        public int $sourceRow,
        public FamilyCode $familyCode,
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public DateTimeImmutable $birthDate,
        public string $sexCode,
        public string $documentTypeCode,
        public string $documentNumber,
        public string $email,
        public string $relationshipTypeCode,
        public bool $isPrimary,
        public DateTimeImmutable $startedAt,
        public ?SensitivePlaintextPassword $initialPassword,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'sourceRow' => $this->sourceRow,
            'familyCode' => $this->familyCode->value(),
            'initialPassword' => $this->initialPassword === null ? null : '[REDACTED]',
        ];
    }
}
