<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Contract;

use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationManifest;

interface AcademicInitializationManifestReader
{
    public function read(string $localPath): AcademicInitializationManifest;
}
