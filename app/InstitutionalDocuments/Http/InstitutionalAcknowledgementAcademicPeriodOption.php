<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

final readonly class InstitutionalAcknowledgementAcademicPeriodOption
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $startsOn,
        public string $endsOn,
        public string $status,
    ) {
    }
}
