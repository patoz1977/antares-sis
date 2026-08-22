<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Family\Domain\FamilyRepository;
use App\IdentityAccess\Application\Contract\Clock;
use App\Student\Domain\StudentRepository;
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
            return (new EnrollmentDraftInitializer(
                $this->students,
                $this->families,
                $this->academicPeriods,
                $this->enrollments,
                $this->academicReferences,
                $this->clock,
            ))->initialize($input);
        });
    }
}
