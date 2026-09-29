<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization;

use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationPreviewResult;

final readonly class PreviewAcademicInitialization
{
    public function __construct(private AcademicInitializationPreflight $preflight)
    {
    }

    public function handle(string $localPath, string $academicPeriodCode): AcademicInitializationPreviewResult
    {
        return $this->preflight->handle($localPath, $academicPeriodCode);
    }
}
