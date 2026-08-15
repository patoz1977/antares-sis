<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Dto;

final readonly class UpdateAcknowledgementRequirementInput
{
    public function __construct(
        public int $requirementId,
        public int $academicPeriodId,
        public string $title,
        public string $url,
        public ?string $officialReference,
    ) {
    }
}
