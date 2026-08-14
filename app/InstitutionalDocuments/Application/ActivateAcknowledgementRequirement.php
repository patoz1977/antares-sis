<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;

final readonly class ActivateAcknowledgementRequirement
{
    public function __construct(private AcknowledgementRequirementRepository $requirements)
    {
    }

    public function handle(int $requirementId): AcknowledgementRequirementOutput
    {
        $requirement = AcknowledgementRequirementApplicationSupport::load(
            $this->requirements,
            new AcknowledgementRequirementId($requirementId),
        );
        $requirement->activate();

        return AcknowledgementRequirementApplicationSupport::save($this->requirements, $requirement);
    }
}
