<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\UpdateEnrollmentTransportInformationInput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use Core\Application\TransactionRunner;

final readonly class UpdateEnrollmentTransportInformation
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateEnrollmentTransportInformationInput $input): EnrollmentOutput
    {
        return EnrollmentApplicationSupport::update(
            $this->enrollments,
            $this->transactions,
            $input,
            static function (Enrollment $enrollment) use ($input): void {
                $enrollment->updateTransportInformation(new TransportInformation(
                    $input->requiresInstitutionalTransport,
                ));
            },
        );
    }
}
