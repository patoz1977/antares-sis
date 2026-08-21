<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicSectionReference;

interface AcademicPlacementReferenceProvider
{
    public function findGradeById(int $gradeId): ?AcademicGradeReference;

    public function findSectionById(int $sectionId): ?AcademicSectionReference;

    public function findNextActiveGradeAfterSortOrder(int $sortOrder): ?AcademicGradeReference;
}
