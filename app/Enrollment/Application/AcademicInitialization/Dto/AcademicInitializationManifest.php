<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationManifest
{
    /**
     * @param list<AcademicInitializationManifestRow> $rows
     * @param list<AcademicInitializationIssue> $issues
     */
    public function __construct(
        public array $rows,
        public array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return $this->rows !== [] && $this->issues === [];
    }
}
