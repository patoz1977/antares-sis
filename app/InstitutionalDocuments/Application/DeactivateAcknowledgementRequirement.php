<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;

final readonly class DeactivateAcknowledgementRequirement
{
    public function __construct(private AcknowledgementRequirementRepository $requirements)
    {
    }

    public function handle(int $requirementId, int $academicPeriodId): AcknowledgementRequirementOutput
    {
        $requirement = AcknowledgementRequirementApplicationSupport::loadForPeriod(
            $this->requirements,
            new AcknowledgementRequirementId($requirementId),
            new AcademicPeriodId($academicPeriodId),
        );
        $requirement->deactivate();

        return AcknowledgementRequirementApplicationSupport::save($this->requirements, $requirement);
    }
}
