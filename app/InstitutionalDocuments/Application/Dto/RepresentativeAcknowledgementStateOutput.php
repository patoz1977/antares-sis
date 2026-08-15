<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Dto;

use DateTimeImmutable;

final readonly class RepresentativeAcknowledgementStateOutput
{
    /** @param list<AcknowledgementRequirementOutput> $activeRequirements */
    public function __construct(
        public int $representativeId,
        public int $academicPeriodId,
        public bool $satisfied,
        public bool $completed,
        public ?int $completionId,
        public ?DateTimeImmutable $completedAt,
        public array $activeRequirements,
    ) {
    }
}
