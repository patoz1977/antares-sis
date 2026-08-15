<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Application\Dto\UpdateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;

final readonly class UpdateAcknowledgementRequirement
{
    public function __construct(private AcknowledgementRequirementRepository $requirements)
    {
    }

    public function handle(UpdateAcknowledgementRequirementInput $input): AcknowledgementRequirementOutput
    {
        $id = new AcknowledgementRequirementId($input->requirementId);
        $requirement = AcknowledgementRequirementApplicationSupport::loadForPeriod(
            $this->requirements,
            $id,
            new AcademicPeriodId($input->academicPeriodId),
        );
        $hasAcknowledgements = $this->requirements->hasAcknowledgements($id);
        $requirement->update(
            new AcknowledgementRequirementTitle($input->title),
            new AcknowledgementRequirementUrl($input->url),
            $input->officialReference === null
                ? null
                : new AcknowledgementOfficialReference($input->officialReference),
            $hasAcknowledgements,
        );

        return AcknowledgementRequirementApplicationSupport::save($this->requirements, $requirement);
    }
}
