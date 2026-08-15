<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

final readonly class AcademicPlacement
{
    public function __construct(
        private GradeId $gradeId,
        private ?SectionId $sectionId,
    ) {
    }

    public function gradeId(): GradeId
    {
        return $this->gradeId;
    }

    public function sectionId(): ?SectionId
    {
        return $this->sectionId;
    }

    public function equals(self $other): bool
    {
        return $this->gradeId->equals($other->gradeId)
            && (($this->sectionId === null && $other->sectionId === null)
                || ($this->sectionId !== null
                    && $other->sectionId !== null
                    && $this->sectionId->equals($other->sectionId)));
    }
}
