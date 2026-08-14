<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Contract;

interface InstitutionalAcknowledgementSatisfaction
{
    public function isSatisfied(int $representativeId, int $academicPeriodId): bool;
}
