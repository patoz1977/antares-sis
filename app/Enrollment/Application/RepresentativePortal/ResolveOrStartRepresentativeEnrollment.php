<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\StartEnrollmentDraftInput;
use App\Enrollment\Application\RepresentativePortal\Dto\ResolveOrStartRepresentativeEnrollmentInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextChanged;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;
use Core\Application\TransactionRunner;

final readonly class ResolveOrStartRepresentativeEnrollment
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private EnrollmentRepository $enrollments,
        private EnrollmentDraftInitializer $initializer,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(ResolveOrStartRepresentativeEnrollmentInput $input): EnrollmentOutput
    {
        return $this->transactions->run(function () use ($input): EnrollmentOutput {
            $context = $this->authorization->resolveMutationContext(
                $input->expectedFamilyId,
                $input->expectedAcademicPeriodId,
                $input->studentId,
            );
            $existing = $this->enrollments->findByStudentAndAcademicPeriod(
                new StudentId($input->studentId),
                new AcademicPeriodId($context->academicPeriodId),
            );
            if ($existing !== null) {
                if ($existing->familyId()->value() !== $context->familyId) {
                    throw new RepresentativeEnrollmentContextChanged(
                        'Representative Enrollment context changed.'
                    );
                }

                return EnrollmentApplicationSupport::output($existing);
            }

            return $this->initializer->initialize(
                new StartEnrollmentDraftInput(
                    $input->studentId,
                    $context->familyId,
                    $context->academicPeriodId,
                ),
                $context->lockedStudentFamily,
            );
        });
    }
}
