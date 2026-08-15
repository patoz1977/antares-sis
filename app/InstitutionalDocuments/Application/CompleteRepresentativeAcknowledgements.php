<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\CompleteRepresentativeAcknowledgementsInput;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementCompletionOutput;
use App\InstitutionalDocuments\Application\Exception\InstitutionalAcknowledgementsAlreadyCompleted;
use App\InstitutionalDocuments\Application\Exception\InvalidAcknowledgementConfirmation;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use Core\Application\TransactionRunner;

final readonly class CompleteRepresentativeAcknowledgements
{
    public function __construct(
        private AcknowledgementRequirementRepository $requirements,
        private RepresentativeAcknowledgementCompletionRepository $completions,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(
        CompleteRepresentativeAcknowledgementsInput $input,
    ): RepresentativeAcknowledgementCompletionOutput {
        return $this->transactions->run(function () use ($input): RepresentativeAcknowledgementCompletionOutput {
            $representativeId = new RepresentativeId($input->representativeId);
            $academicPeriodId = new AcademicPeriodId($input->academicPeriodId);
            $this->requirements->lockConfigurationScope($academicPeriodId);
            $existing = $this->completions->findByRepresentativeAndAcademicPeriod(
                $representativeId,
                $academicPeriodId,
            );
            if ($existing !== null) {
                RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                    $existing,
                    $representativeId,
                    $academicPeriodId,
                );
                throw new InstitutionalAcknowledgementsAlreadyCompleted(
                    'Institutional Acknowledgements were already completed for this period.'
                );
            }

            $lockedRequirements = $this->requirements->lockForCompletion($academicPeriodId);
            foreach ($lockedRequirements as $requirement) {
                if (!$requirement instanceof AcknowledgementRequirement
                    || !$requirement->academicPeriodId()->equals($academicPeriodId)
                ) {
                    throw new InvalidAcknowledgementConfirmation(
                        'Institutional Acknowledgements could not resolve the required set.'
                    );
                }
            }
            $activeRequirements = array_values(array_filter(
                $lockedRequirements,
                static fn (AcknowledgementRequirement $requirement): bool => $requirement->isActive(),
            ));
            $expectedIds = array_map(
                static fn (AcknowledgementRequirement $requirement): int =>
                    $requirement->id()?->value()
                    ?? throw new InvalidAcknowledgementConfirmation(
                        'Institutional Acknowledgements could not resolve the required set.'
                    ),
                $activeRequirements,
            );
            sort($expectedIds, SORT_NUMERIC);
            $submittedIds = $this->validatedSubmittedIds($input->acknowledgedRequirementIds);
            if ($submittedIds !== $expectedIds) {
                throw new InvalidAcknowledgementConfirmation(
                    'Institutional Acknowledgements confirmation does not match the current required set.'
                );
            }

            if ($activeRequirements === []) {
                return RepresentativeAcknowledgementCompletionOutput::satisfiedWithoutCompletion(
                    $representativeId,
                    $academicPeriodId,
                );
            }

            $completion = RepresentativeAcknowledgementCompletion::complete(
                $representativeId,
                $academicPeriodId,
                $input->completedAt,
                $activeRequirements,
            );
            $persisted = $this->completions->save($completion);

            return RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                $persisted,
                $representativeId,
                $academicPeriodId,
                $input->completedAt,
                $expectedIds,
            );
        });
    }

    /** @param array<array-key, mixed> $values @return list<int> */
    private function validatedSubmittedIds(array $values): array
    {
        $validated = [];
        foreach ($values as $value) {
            if (!is_int($value) || $value <= 0 || isset($validated[$value])) {
                throw new InvalidAcknowledgementConfirmation(
                    'Institutional Acknowledgements confirmation is invalid.'
                );
            }
            $validated[$value] = true;
        }

        $ids = array_keys($validated);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
