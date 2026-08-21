<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\UpdateEnrollmentAcademicPlacementInput;
use App\Enrollment\Application\Exception\AcademicPlacementReferenceNotFound;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use Core\Application\TransactionRunner;

final readonly class UpdateEnrollmentAcademicPlacement
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private AcademicPlacementReferenceProvider $academicReferences,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateEnrollmentAcademicPlacementInput $input): EnrollmentOutput
    {
        return EnrollmentApplicationSupport::update(
            $this->enrollments,
            $this->transactions,
            $input,
            function (Enrollment $enrollment) use ($input): void {
                if ($this->academicReferences->findGradeById($input->gradeId) === null) {
                    throw new AcademicPlacementReferenceNotFound('Grade was not found.');
                }
                if ($input->sectionId !== null
                    && $this->academicReferences->findSectionById($input->sectionId) === null
                ) {
                    throw new AcademicPlacementReferenceNotFound('Section was not found.');
                }

                $enrollment->updateAcademicPlacement(new AcademicPlacement(
                    new GradeId($input->gradeId),
                    $input->sectionId === null ? null : new SectionId($input->sectionId),
                ));
            },
        );
    }
}
