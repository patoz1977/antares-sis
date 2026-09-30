<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

final readonly class AcademicInitializationPreviewGrant
{
    public function __construct(
        public string $fileDigest,
        public string $stateDigest,
        public string $academicPeriodCode,
    ) {
    }
}
