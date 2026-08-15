<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Application\Dto\UpdateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Exception\AcknowledgementRequirementNotFound;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use Core\Application\TransactionRunner;

final readonly class UpdateAcknowledgementRequirement
{
    public function __construct(
        private AcknowledgementRequirementRepository $requirements,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateAcknowledgementRequirementInput $input): AcknowledgementRequirementOutput
    {
        return $this->transactions->run(function () use ($input): AcknowledgementRequirementOutput {
            $id = new AcknowledgementRequirementId($input->requirementId);
            $academicPeriodId = new AcademicPeriodId($input->academicPeriodId);
            $requirement = $this->requirements->lockForPostUseUpdate($id);
            if ($requirement === null
                || $requirement->id() === null
                || !$requirement->id()->equals($id)
                || !$requirement->academicPeriodId()->equals($academicPeriodId)
            ) {
                throw new AcknowledgementRequirementNotFound(
                    'Acknowledgement Requirement was not found.'
                );
            }

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
        });
    }
}
