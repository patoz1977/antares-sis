<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;

interface AcknowledgementRequirementRepository
{
    public function findById(AcknowledgementRequirementId $id): ?AcknowledgementRequirement;

    /** @return list<AcknowledgementRequirement> */
    public function findByAcademicPeriodId(AcademicPeriodId $academicPeriodId): array;

    public function lockForPostUseUpdate(
        AcknowledgementRequirementId $id,
    ): ?AcknowledgementRequirement;

    /** @return list<AcknowledgementRequirement> */
    public function lockForCompletion(AcademicPeriodId $academicPeriodId): array;

    public function hasAcknowledgements(AcknowledgementRequirementId $id): bool;

    public function save(AcknowledgementRequirement $requirement): AcknowledgementRequirement;
}
