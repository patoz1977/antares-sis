<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;

interface RepresentativeAcknowledgementCompletionRepository
{
    public function findByRepresentativeAndAcademicPeriod(
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
    ): ?RepresentativeAcknowledgementCompletion;

    public function save(
        RepresentativeAcknowledgementCompletion $completion,
    ): RepresentativeAcknowledgementCompletion;
}
