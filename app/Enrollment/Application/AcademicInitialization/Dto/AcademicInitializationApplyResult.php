<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationApplyResult
{
    public function __construct(
        public int $rows,
        public int $createdDrafts,
        public int $placementsSet,
        public int $alreadyCorrect,
    ) {
    }

    /** @return array{rows: int, created_drafts: int, placements_set: int, already_correct: int} */
    public function safeOutput(): array
    {
        return [
            'rows' => $this->rows,
            'created_drafts' => $this->createdDrafts,
            'placements_set' => $this->placementsSet,
            'already_correct' => $this->alreadyCorrect,
        ];
    }
}
