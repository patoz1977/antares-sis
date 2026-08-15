<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Application\Dto\CreateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use Core\Application\TransactionRunner;

final readonly class CreateAcknowledgementRequirement
{
    public function __construct(
        private AcknowledgementRequirementRepository $requirements,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(CreateAcknowledgementRequirementInput $input): AcknowledgementRequirementOutput
    {
        return $this->transactions->run(function () use ($input): AcknowledgementRequirementOutput {
            $academicPeriodId = new AcademicPeriodId($input->academicPeriodId);
            $this->requirements->lockConfigurationScope($academicPeriodId);
            $requirement = AcknowledgementRequirement::create(
                $academicPeriodId,
                new AcknowledgementRequirementTitle($input->title),
                new AcknowledgementRequirementUrl($input->url),
                $input->officialReference === null
                    ? null
                    : new AcknowledgementOfficialReference($input->officialReference),
                AcknowledgementRequirementApplicationSupport::status($input->status),
            );

            return AcknowledgementRequirementApplicationSupport::save($this->requirements, $requirement);
        });
    }
}
