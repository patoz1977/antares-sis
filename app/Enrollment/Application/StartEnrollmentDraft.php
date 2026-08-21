<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\Exception\AcademicPlacementReferenceNotFound;
use App\Enrollment\Application\Exception\EnrollmentAcademicPeriodNotFound;
use App\Enrollment\Application\Exception\EnrollmentAlreadyExists;
use App\Enrollment\Application\Exception\EnrollmentFamilyContextUnavailable;
use App\Enrollment\Application\Exception\EnrollmentStudentNotFound;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyId as FamilyDomainId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\IdentityAccess\Application\Contract\Clock;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\ValueObject\StudentId as StudentDomainId;
use Core\Application\TransactionRunner;

final readonly class StartEnrollmentDraft
{
    public function __construct(
        private StudentRepository $students,
        private FamilyRepository $families,
        private AcademicPeriodRepository $academicPeriods,
        private EnrollmentRepository $enrollments,
        private AcademicPlacementReferenceProvider $academicReferences,
        private Clock $clock,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(StartEnrollmentDraftInput $input): EnrollmentOutput
    {
        return $this->transactions->run(function () use ($input): EnrollmentOutput {
            $studentId = new StudentId($input->studentId);
            $familyId = new FamilyId($input->familyId);
            $academicPeriodId = new AcademicPeriodId($input->academicPeriodId);

            if ($this->students->findById(new StudentDomainId($studentId->value())) === null) {
                throw new EnrollmentStudentNotFound('Student for Enrollment was not found.');
            }

            $activeFamily = $this->families->findActiveByStudentId(
                new FamilyStudentId($studentId->value()),
            );
            if ($activeFamily === null
                || $activeFamily->id()?->equals(new FamilyDomainId($familyId->value())) !== true
            ) {
                throw new EnrollmentFamilyContextUnavailable(
                    'Student does not have the requested active Family context.'
                );
            }

            if ($this->academicPeriods->findById(
                new CoreAcademicPeriodId($academicPeriodId->value()),
            ) === null) {
                throw new EnrollmentAcademicPeriodNotFound('AcademicPeriod for Enrollment was not found.');
            }

            if ($this->enrollments->findByStudentAndAcademicPeriod($studentId, $academicPeriodId) !== null) {
                throw new EnrollmentAlreadyExists(
                    'Enrollment already exists for the Student and AcademicPeriod.'
                );
            }

            $placement = $this->placement($input->gradeId, $input->sectionId);
            $draft = Enrollment::startDraft(
                $studentId,
                $familyId,
                $academicPeriodId,
                $this->clock->now(),
                $placement,
            );

            return EnrollmentApplicationSupport::save($this->enrollments, $draft);
        });
    }

    private function placement(?int $gradeId, ?int $sectionId): ?AcademicPlacement
    {
        if ($gradeId === null) {
            if ($sectionId !== null) {
                throw new AcademicPlacementReferenceNotFound(
                    'Section cannot be selected without a Grade.'
                );
            }

            return null;
        }

        if ($this->academicReferences->findGradeById($gradeId) === null) {
            throw new AcademicPlacementReferenceNotFound('Grade was not found.');
        }
        if ($sectionId !== null && $this->academicReferences->findSectionById($sectionId) === null) {
            throw new AcademicPlacementReferenceNotFound('Section was not found.');
        }

        return new AcademicPlacement(
            new GradeId($gradeId),
            $sectionId === null ? null : new SectionId($sectionId),
        );
    }
}
