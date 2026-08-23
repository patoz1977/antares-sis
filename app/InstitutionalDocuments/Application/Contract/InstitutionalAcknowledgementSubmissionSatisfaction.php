<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Contract;

interface InstitutionalAcknowledgementSubmissionSatisfaction
{
    public function isSatisfiedInStableContext(
        int $representativeId,
        int $academicPeriodId,
    ): bool;
}
