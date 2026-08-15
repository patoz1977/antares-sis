<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Dto;

use DateTimeImmutable;

final readonly class CompleteRepresentativeAcknowledgementsInput
{
    /** @param array<array-key, mixed> $acknowledgedRequirementIds */
    public function __construct(
        public int $representativeId,
        public int $academicPeriodId,
        public array $acknowledgedRequirementIds,
        public DateTimeImmutable $completedAt,
    ) {
    }
}
