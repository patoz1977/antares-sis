<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization;

use App\AcademicCore\Application\AcademicPeriodCodeLookup;
use App\AcademicCore\Application\AcademicPlacementCodeReferenceProvider;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\Enrollment\Application\AcademicInitialization\Contract\AcademicInitializationManifestReader;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationClassification;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationIssue;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationManifestRow;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationPreviewItem;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationPreviewResult;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\InstitutionalCode;
use Throwable;

final readonly class AcademicInitializationPreflight
{
    private const ACTIVE = 'ACTIVE';

    public function __construct(
        private AcademicInitializationManifestReader $reader,
        private StudentRepository $students,
        private FamilyRepository $families,
        private AcademicPeriodCodeLookup $academicPeriods,
        private AcademicPlacementCodeReferenceProvider $academicReferences,
        private EnrollmentRepository $enrollments,
        private AcademicInitializationStateClassifier $classifier,
    ) {
    }

    public function handle(string $localPath, string $academicPeriodCode): AcademicInitializationPreviewResult
    {
        $manifest = $this->reader->read($localPath);
        if (!$manifest->isValid()) {
            return new AcademicInitializationPreviewResult(
                trim($academicPeriodCode),
                $manifest->rows,
                [],
                $manifest->issues,
            );
        }

        try {
            $period = $this->academicPeriods->findByCode(new AcademicPeriodCode($academicPeriodCode));
        } catch (Throwable) {
            $period = null;
        }
        if ($period === null || !$period->isActive() || $period->id() === null) {
            return new AcademicInitializationPreviewResult(
                trim($academicPeriodCode),
                $manifest->rows,
                [],
                [new AcademicInitializationIssue(
                    'ACADEMIC_PERIOD_INVALID',
                    0,
                    'academic_period_code',
                    'El período académico indicado no existe o no está ACTIVE.',
                )],
            );
        }

        $items = [];
        $issues = [];
        $resolvedStudents = [];
        foreach ($manifest->rows as $row) {
            try {
                $result = $this->row($row, $period->id()->value());
                $items[] = $result;
                if (isset($resolvedStudents[$result->studentId])) {
                    $issues[] = new AcademicInitializationIssue(
                        'STUDENT_IDENTITY_AMBIGUOUS',
                        $row->sourceRow,
                        'institutional_code',
                        'Más de una fila resolvió al mismo Student; el lote completo fue bloqueado.',
                    );
                }
                $resolvedStudents[$result->studentId] = true;
                if ($result->classification === AcademicInitializationClassification::Conflict) {
                    $issues[] = new AcademicInitializationIssue(
                        'ENROLLMENT_CONFLICT',
                        $row->sourceRow,
                        'institutional_code',
                        $result->message,
                    );
                }
            } catch (Throwable $exception) {
                $issues[] = new AcademicInitializationIssue(
                    'REFERENCE_INVALID',
                    $row->sourceRow,
                    null,
                    $this->safeReferenceMessage($exception->getMessage()),
                );
            }
        }

        return new AcademicInitializationPreviewResult(
            $period->code()->value(),
            $manifest->rows,
            $items,
            $issues,
        );
    }

    private function row(
        AcademicInitializationManifestRow $row,
        int $academicPeriodId,
    ): AcademicInitializationPreviewItem {
        $student = $this->students->findByInstitutionalCode(new InstitutionalCode($row->institutionalCode));
        $studentId = $student?->id();
        if ($student === null || $studentId === null || !$student->isActive()) {
            throw new \RuntimeException('El código institucional no corresponde a un Student ACTIVE único.');
        }

        $family = $this->families->findActiveByStudentId(new FamilyStudentId($studentId->value()));
        $familyId = $family?->id();
        if ($family === null || $familyId === null) {
            throw new \RuntimeException('El Student no tiene una Family activa autoritativa.');
        }

        $grade = $this->academicReferences->findGradeByCode($row->gradeCode);
        if ($grade === null || $grade->status !== self::ACTIVE) {
            throw new \RuntimeException('El código de Grade no existe o no está ACTIVE.');
        }
        $section = $this->academicReferences->findSectionByCode($row->sectionCode);
        if ($section === null || $section->status !== self::ACTIVE) {
            throw new \RuntimeException('El código de Section no existe o no está ACTIVE.');
        }

        $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId($studentId->value()),
            new AcademicPeriodId($academicPeriodId),
        );
        [$classification, $message] = $this->classifier->classify(
            $enrollment,
            $grade->id,
            $section->id,
        );
        $placement = $enrollment?->academicPlacement();

        return new AcademicInitializationPreviewItem(
            $row->sourceRow,
            $row->institutionalCode,
            $row->gradeCode,
            $row->sectionCode,
            $classification,
            $message,
            $studentId->value(),
            $familyId->value(),
            $academicPeriodId,
            $grade->id,
            $section->id,
            $enrollment?->id()?->value(),
            $enrollment?->status()->value,
            $placement?->gradeId()->value(),
            $placement?->sectionId()?->value(),
        );
    }

    private function safeReferenceMessage(string $message): string
    {
        foreach ([
            'código institucional', 'Family activa', 'Grade', 'Section',
        ] as $allowedFragment) {
            if (str_contains($message, $allowedFragment)) {
                return $message;
            }
        }

        return 'La fila no pudo resolverse de forma segura contra el estado actual.';
    }
}
