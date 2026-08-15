<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;

final readonly class GetAcknowledgementRequirements
{
    public function __construct(private AcknowledgementRequirementRepository $requirements)
    {
    }

    /** @return list<AcknowledgementRequirementOutput> */
    public function handle(int $academicPeriodId): array
    {
        return array_map(
            static fn (AcknowledgementRequirement $requirement): AcknowledgementRequirementOutput =>
                AcknowledgementRequirementOutput::fromRequirement($requirement),
            AcknowledgementRequirementApplicationSupport::forPeriod(
                $this->requirements,
                new AcademicPeriodId($academicPeriodId),
            ),
        );
    }
}
