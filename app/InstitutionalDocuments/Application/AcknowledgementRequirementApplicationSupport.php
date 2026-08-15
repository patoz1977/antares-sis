<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Application\Exception\AcknowledgementRequirementNotFound;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;

final class AcknowledgementRequirementApplicationSupport
{
    public static function load(
        AcknowledgementRequirementRepository $requirements,
        AcknowledgementRequirementId $id,
    ): AcknowledgementRequirement {
        $requirement = $requirements->findById($id);
        if ($requirement === null) {
            throw new AcknowledgementRequirementNotFound('Acknowledgement Requirement was not found.');
        }

        AcknowledgementRequirementOutput::fromRequirement($requirement);

        return $requirement;
    }

    public static function loadForPeriod(
        AcknowledgementRequirementRepository $requirements,
        AcknowledgementRequirementId $id,
        AcademicPeriodId $academicPeriodId,
    ): AcknowledgementRequirement {
        $requirement = self::load($requirements, $id);
        if (!$requirement->academicPeriodId()->equals($academicPeriodId)) {
            throw new AcknowledgementRequirementNotFound('Acknowledgement Requirement was not found.');
        }

        return $requirement;
    }

    /** @return list<AcknowledgementRequirement> */
    public static function forPeriod(
        AcknowledgementRequirementRepository $requirements,
        AcademicPeriodId $academicPeriodId,
    ): array {
        $result = $requirements->findByAcademicPeriodId($academicPeriodId);
        foreach ($result as $requirement) {
            if (!$requirement instanceof AcknowledgementRequirement
                || !$requirement->academicPeriodId()->equals($academicPeriodId)
            ) {
                throw new InvalidPersistedAcknowledgementResult(
                    'Acknowledgement Requirement query returned incoherent AcademicPeriod state.'
                );
            }
            AcknowledgementRequirementOutput::fromRequirement($requirement);
        }

        return $result;
    }

    /** @return list<AcknowledgementRequirement> */
    public static function activeForPeriod(
        AcknowledgementRequirementRepository $requirements,
        AcademicPeriodId $academicPeriodId,
    ): array {
        return array_values(array_filter(
            self::forPeriod($requirements, $academicPeriodId),
            static fn (AcknowledgementRequirement $requirement): bool => $requirement->isActive(),
        ));
    }

    public static function save(
        AcknowledgementRequirementRepository $requirements,
        AcknowledgementRequirement $requirement,
    ): AcknowledgementRequirementOutput {
        return AcknowledgementRequirementOutput::fromRequirement(
            $requirements->save($requirement),
            $requirement,
        );
    }

    public static function status(string $status): AcknowledgementRequirementStatus
    {
        return AcknowledgementRequirementStatus::tryFrom($status)
            ?? throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement Requirement status must be ACTIVE or INACTIVE.'
            );
    }

    private function __construct()
    {
    }
}
