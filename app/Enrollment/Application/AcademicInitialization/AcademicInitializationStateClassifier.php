<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization;

use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationClassification;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentStatus;

final class AcademicInitializationStateClassifier
{
    /** @return array{AcademicInitializationClassification, string} */
    public function classify(?Enrollment $enrollment, int $gradeId, int $sectionId): array
    {
        if ($enrollment === null) {
            return [
                AcademicInitializationClassification::CreateDraft,
                'Se creará una matrícula DRAFT con la ubicación académica indicada.',
            ];
        }

        if ($enrollment->status() !== EnrollmentStatus::Draft) {
            return [
                AcademicInitializationClassification::Conflict,
                'La matrícula existente no está en DRAFT y no puede modificarse automáticamente.',
            ];
        }

        $placement = $enrollment->academicPlacement();
        if ($placement === null) {
            return [
                AcademicInitializationClassification::SetPlacement,
                'La matrícula DRAFT existente recibirá la ubicación académica indicada.',
            ];
        }

        if ($placement->gradeId()->value() === $gradeId
            && $placement->sectionId()?->value() === $sectionId
        ) {
            return [
                AcademicInitializationClassification::AlreadyCorrect,
                'La matrícula DRAFT ya tiene exactamente la ubicación académica indicada.',
            ];
        }

        return [
            AcademicInitializationClassification::Conflict,
            'La matrícula DRAFT existente tiene una ubicación académica diferente.',
        ];
    }
}
