<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationPreviewItem
{
    public function __construct(
        public int $sourceRow,
        public string $institutionalCode,
        public string $gradeCode,
        public string $sectionCode,
        public AcademicInitializationClassification $classification,
        public string $message,
        public int $studentId,
        public int $familyId,
        public int $academicPeriodId,
        public int $resolvedGradeId,
        public int $resolvedSectionId,
        public ?int $enrollmentId,
        public ?string $enrollmentStatus,
        public ?int $currentGradeId,
        public ?int $currentSectionId,
    ) {
    }

    /** @return array{row: int, institutional_code: string, grade_code: string, section_code: string, classification: string, message: string} */
    public function safeOutput(): array
    {
        return [
            'row' => $this->sourceRow,
            'institutional_code' => $this->institutionalCode,
            'grade_code' => $this->gradeCode,
            'section_code' => $this->sectionCode,
            'classification' => $this->classification->value,
            'message' => $this->message,
        ];
    }

    /** @return array<string, int|string|null> */
    public function fingerprintState(): array
    {
        return [
            'institutional_code' => $this->institutionalCode,
            'student_id' => $this->studentId,
            'family_id' => $this->familyId,
            'academic_period_id' => $this->academicPeriodId,
            'resolved_grade_id' => $this->resolvedGradeId,
            'resolved_section_id' => $this->resolvedSectionId,
            'enrollment_id' => $this->enrollmentId,
            'enrollment_status' => $this->enrollmentStatus,
            'current_grade_id' => $this->currentGradeId,
            'current_section_id' => $this->currentSectionId,
            'classification' => $this->classification->value,
        ];
    }
}
