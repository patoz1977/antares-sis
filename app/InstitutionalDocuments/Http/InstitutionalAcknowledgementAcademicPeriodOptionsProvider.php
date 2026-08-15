<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

interface InstitutionalAcknowledgementAcademicPeriodOptionsProvider
{
    /** @return list<InstitutionalAcknowledgementAcademicPeriodOption> */
    public function all(): array;

    public function findById(int $id): ?InstitutionalAcknowledgementAcademicPeriodOption;
}
