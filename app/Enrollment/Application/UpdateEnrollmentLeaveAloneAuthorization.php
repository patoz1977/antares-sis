<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\UpdateEnrollmentLeaveAloneAuthorizationInput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use Core\Application\TransactionRunner;

final readonly class UpdateEnrollmentLeaveAloneAuthorization
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateEnrollmentLeaveAloneAuthorizationInput $input): EnrollmentOutput
    {
        return EnrollmentApplicationSupport::update(
            $this->enrollments,
            $this->transactions,
            $input,
            static function (Enrollment $enrollment) use ($input): void {
                $enrollment->updateLeaveAloneAuthorization($input->isAuthorizedToLeaveAlone);
            },
        );
    }
}
