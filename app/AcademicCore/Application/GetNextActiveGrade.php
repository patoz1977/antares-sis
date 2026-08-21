<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Exception\AcademicReferenceNotFound;

final readonly class GetNextActiveGrade
{
    public function __construct(private AcademicPlacementReferenceProvider $references)
    {
    }

    public function handle(int $basisGradeId): ?AcademicGradeReference
    {
        $basis = $this->references->findGradeById($basisGradeId);
        if ($basis === null) {
            throw new AcademicReferenceNotFound('Basis Grade was not found.');
        }

        return $this->references->findNextActiveGradeAfterSortOrder($basis->sortOrder);
    }
}
