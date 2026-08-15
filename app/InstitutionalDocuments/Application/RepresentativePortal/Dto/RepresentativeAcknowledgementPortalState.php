<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal\Dto;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use DateTimeImmutable;

final readonly class RepresentativeAcknowledgementPortalState
{
    /** @param list<AcknowledgementRequirementOutput> $activeRequirements */
    public function __construct(
        public RepresentativeAcknowledgementContext $context,
        public string $status,
        public bool $satisfied,
        public bool $hasCompletion,
        public ?int $completionId,
        public ?DateTimeImmutable $completedAt,
        public array $activeRequirements,
    ) {
    }
}
