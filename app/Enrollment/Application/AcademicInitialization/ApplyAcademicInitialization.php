<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization;

use App\AcademicCore\Application\AcademicPeriodCodeLookup;
use App\AcademicCore\Application\AcademicPlacementCodeReferenceProvider;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationApplyResult;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationClassification;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationManifestRow;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationPreviewItem;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationPreviewResult;
use App\Enrollment\Application\AcademicInitialization\Exception\AcademicInitializationRejected;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\Student\Application\LockingStudentRepository;
use App\Student\Domain\ValueObject\InstitutionalCode;
use Core\Application\TransactionRunner;

final readonly class ApplyAcademicInitialization
{
    public function __construct(
        private AcademicInitializationPreflight $preflight,
        private LockingStudentRepository $students,
        private FamilyRepository $families,
        private AcademicPeriodRepository $academicPeriodRepository,
        private AcademicPeriodCodeLookup $academicPeriods,
        private AcademicPlacementCodeReferenceProvider $academicReferences,
        private EnrollmentRepository $enrollments,
        private EnrollmentDraftInitializer $initializer,
        private AcademicInitializationStateClassifier $classifier,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(
        string $localPath,
        string $academicPeriodCode,
        string $expectedStateDigest,
    ): AcademicInitializationApplyResult {
        $preflight = $this->preflight->handle($localPath, $academicPeriodCode);
        if (!$preflight->isApplicable()
            || !hash_equals($expectedStateDigest, $preflight->stateDigest())
        ) {
            throw new AcademicInitializationRejected(
                'El estado cambió o el manifest ya no supera el preflight completo.'
            );
        }

        return $this->transactions->run(
            fn (): AcademicInitializationApplyResult => $this->applyLocked($preflight, $expectedStateDigest),
        );
    }

    private function applyLocked(
        AcademicInitializationPreviewResult $preflight,
        string $expectedStateDigest,
    ): AcademicInitializationApplyResult {
        $this->academicPeriodRepository->lockActiveContextForRead();
        $period = $this->academicPeriods->findByCode(
            new AcademicPeriodCode($preflight->academicPeriodCode),
        );
        if ($period === null || !$period->isActive() || $period->id() === null) {
            throw new AcademicInitializationRejected('El período académico cambió durante Apply.');
        }

        $rows = $preflight->rows;
        usort(
            $rows,
            static fn (AcademicInitializationManifestRow $left, AcademicInitializationManifestRow $right): int =>
                strcmp($left->institutionalCode, $right->institutionalCode),
        );

        $contexts = [];
        $lockedItems = [];
        foreach ($rows as $row) {
            [$item, $family, $enrollment] = $this->lockRow($row, $period->id()->value());
            if ($item->classification === AcademicInitializationClassification::Conflict) {
                throw new AcademicInitializationRejected('Se detectó un conflicto durante Apply.');
            }
            $contexts[] = [$item, $family, $enrollment];
            $lockedItems[] = $item;
        }

        $lockedState = new AcademicInitializationPreviewResult(
            $period->code()->value(),
            $rows,
            $lockedItems,
            [],
        );
        if (!hash_equals($expectedStateDigest, $lockedState->stateDigest())) {
            throw new AcademicInitializationRejected(
                'El estado cambió concurrentemente; Apply fue revertido antes de escribir.'
            );
        }

        $created = 0;
        $placementsSet = 0;
        $alreadyCorrect = 0;
        foreach ($contexts as [$item, $family, $enrollment]) {
            if ($item->classification === AcademicInitializationClassification::CreateDraft) {
                $this->initializer->initialize(new StartEnrollmentDraftInput(
                    $item->studentId,
                    $item->familyId,
                    $item->academicPeriodId,
                    $item->resolvedGradeId,
                    $item->resolvedSectionId,
                ), $family);
                ++$created;
                continue;
            }
            if ($item->classification === AcademicInitializationClassification::SetPlacement) {
                if (!$enrollment instanceof Enrollment) {
                    throw new AcademicInitializationRejected('La matrícula esperada desapareció durante Apply.');
                }
                $enrollment->updateAcademicPlacement(new AcademicPlacement(
                    new GradeId($item->resolvedGradeId),
                    new SectionId($item->resolvedSectionId),
                ));
                EnrollmentApplicationSupport::save($this->enrollments, $enrollment);
                ++$placementsSet;
                continue;
            }
            ++$alreadyCorrect;
        }

        return new AcademicInitializationApplyResult(
            count($rows),
            $created,
            $placementsSet,
            $alreadyCorrect,
        );
    }

    /** @return array{AcademicInitializationPreviewItem, Family, ?Enrollment} */
    private function lockRow(
        AcademicInitializationManifestRow $row,
        int $academicPeriodId,
    ): array {
        $student = $this->students->findByInstitutionalCodeForUpdate(
            new InstitutionalCode($row->institutionalCode),
        );
        $studentId = $student?->id();
        if ($student === null || $studentId === null || !$student->isActive()) {
            throw new AcademicInitializationRejected('Student cambió durante Apply.');
        }
        $family = $this->families->findActiveByStudentIdForUpdate(
            new FamilyStudentId($studentId->value()),
        );
        $familyId = $family?->id();
        if ($family === null || $familyId === null) {
            throw new AcademicInitializationRejected('Family cambió durante Apply.');
        }
        $grade = $this->academicReferences->findGradeByCode($row->gradeCode);
        $section = $this->academicReferences->findSectionByCode($row->sectionCode);
        if ($grade === null || $grade->status !== 'ACTIVE'
            || $section === null || $section->status !== 'ACTIVE'
        ) {
            throw new AcademicInitializationRejected('Las referencias académicas cambiaron durante Apply.');
        }

        $enrollment = $this->enrollments->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId($studentId->value()),
            new AcademicPeriodId($academicPeriodId),
        );
        if ($enrollment !== null) {
            $enrollmentId = $enrollment->id();
            if ($enrollmentId === null) {
                throw new AcademicInitializationRejected('Enrollment persistido sin identidad.');
            }
            $enrollment = $this->enrollments->findByIdForUpdate($enrollmentId);
            if ($enrollment === null) {
                throw new AcademicInitializationRejected('Enrollment desapareció durante Apply.');
            }
        }
        [$classification, $message] = $this->classifier->classify(
            $enrollment,
            $grade->id,
            $section->id,
        );
        $placement = $enrollment?->academicPlacement();

        return [new AcademicInitializationPreviewItem(
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
        ), $family, $enrollment];
    }
}
