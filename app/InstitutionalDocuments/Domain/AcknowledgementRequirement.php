<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;

final class AcknowledgementRequirement
{
    private function __construct(
        private readonly ?AcknowledgementRequirementId $id,
        private readonly AcademicPeriodId $academicPeriodId,
        private AcknowledgementRequirementTitle $title,
        private AcknowledgementRequirementUrl $url,
        private ?AcknowledgementOfficialReference $officialReference,
        private AcknowledgementRequirementStatus $status,
    ) {
    }

    public static function create(
        AcademicPeriodId $academicPeriodId,
        AcknowledgementRequirementTitle $title,
        AcknowledgementRequirementUrl $url,
        ?AcknowledgementOfficialReference $officialReference,
        AcknowledgementRequirementStatus $status,
    ): self {
        return new self(null, $academicPeriodId, $title, $url, $officialReference, $status);
    }

    public static function reconstitute(
        AcknowledgementRequirementId $id,
        AcademicPeriodId $academicPeriodId,
        AcknowledgementRequirementTitle $title,
        AcknowledgementRequirementUrl $url,
        ?AcknowledgementOfficialReference $officialReference,
        AcknowledgementRequirementStatus $status,
    ): self {
        return new self($id, $academicPeriodId, $title, $url, $officialReference, $status);
    }

    public function id(): ?AcknowledgementRequirementId
    {
        return $this->id;
    }

    public function academicPeriodId(): AcademicPeriodId
    {
        return $this->academicPeriodId;
    }

    public function title(): AcknowledgementRequirementTitle
    {
        return $this->title;
    }

    public function url(): AcknowledgementRequirementUrl
    {
        return $this->url;
    }

    public function officialReference(): ?AcknowledgementOfficialReference
    {
        return $this->officialReference;
    }

    public function status(): AcknowledgementRequirementStatus
    {
        return $this->status;
    }

    public function update(
        AcknowledgementRequirementTitle $title,
        AcknowledgementRequirementUrl $url,
        ?AcknowledgementOfficialReference $officialReference,
        bool $hasAcknowledgements,
    ): void {
        if ($hasAcknowledgements && !$this->title->equals($title)) {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement requirement title cannot change after its first acknowledgement.'
            );
        }

        if ($hasAcknowledgements && !self::sameOfficialReference(
            $this->officialReference,
            $officialReference,
        )) {
            throw new InvalidInstitutionalAcknowledgementState(
                'Acknowledgement official reference cannot change after its first acknowledgement.'
            );
        }

        $this->title = $title;
        $this->url = $url;
        $this->officialReference = $officialReference;
    }

    public function activate(): void
    {
        $this->status = AcknowledgementRequirementStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = AcknowledgementRequirementStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === AcknowledgementRequirementStatus::Active;
    }

    private static function sameOfficialReference(
        ?AcknowledgementOfficialReference $current,
        ?AcknowledgementOfficialReference $replacement,
    ): bool {
        if ($current === null || $replacement === null) {
            return $current === $replacement;
        }

        return $current->equals($replacement);
    }
}
