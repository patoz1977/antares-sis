<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal\Dto;

final readonly class RepresentativeAcknowledgementContext
{
    public function __construct(
        public int $representativeId,
        public int $academicPeriodId,
        public string $academicPeriodCode,
        public string $academicPeriodName,
        public string $startsOn,
        public string $endsOn,
    ) {
    }
}
