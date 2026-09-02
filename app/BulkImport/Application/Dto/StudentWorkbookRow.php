<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

use App\Family\Domain\ValueObject\FamilyCode;
use App\Student\Domain\ValueObject\InstitutionalCode;
use DateTimeImmutable;

final readonly class StudentWorkbookRow
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
        public ?string $documentTypeCode,
        public ?string $documentNumber,
        public InstitutionalCode $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
