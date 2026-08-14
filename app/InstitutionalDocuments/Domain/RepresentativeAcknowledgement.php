<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementId;

final readonly class RepresentativeAcknowledgement
{
    private function __construct(
        private ?RepresentativeAcknowledgementId $id,
        private AcknowledgementRequirementId $acknowledgementRequirementId,
    ) {
    }

    public static function create(AcknowledgementRequirementId $acknowledgementRequirementId): self
    {
        return new self(null, $acknowledgementRequirementId);
    }

    public static function reconstitute(
        RepresentativeAcknowledgementId $id,
        AcknowledgementRequirementId $acknowledgementRequirementId,
    ): self {
        return new self($id, $acknowledgementRequirementId);
    }

    public function id(): ?RepresentativeAcknowledgementId
    {
        return $this->id;
    }

    public function acknowledgementRequirementId(): AcknowledgementRequirementId
    {
        return $this->acknowledgementRequirementId;
    }
}
