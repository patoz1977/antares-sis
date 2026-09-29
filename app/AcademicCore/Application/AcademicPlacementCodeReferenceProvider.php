<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicSectionReference;

interface AcademicPlacementCodeReferenceProvider
{
    public function findGradeByCode(string $gradeCode): ?AcademicGradeReference;

    public function findSectionByCode(string $sectionCode): ?AcademicSectionReference;
}
