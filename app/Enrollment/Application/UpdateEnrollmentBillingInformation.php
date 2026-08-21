<?php

declare(strict_types=1);

namespace App\Enrollment\Application;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\Dto\UpdateEnrollmentBillingInformationInput;
use App\Enrollment\Application\Support\EnrollmentApplicationSupport;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use Core\Application\TransactionRunner;

final readonly class UpdateEnrollmentBillingInformation
{
    public function __construct(
        private EnrollmentRepository $enrollments,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateEnrollmentBillingInformationInput $input): EnrollmentOutput
    {
        return EnrollmentApplicationSupport::update(
            $this->enrollments,
            $this->transactions,
            $input,
            static function (Enrollment $enrollment) use ($input): void {
                $enrollment->updateBillingInformation(new BillingInformation(
                    new IdentificationTypeId($input->identificationTypeId),
                    $input->identificationNumber,
                    $input->legalName,
                    $input->billingAddress,
                    $input->billingEmail,
                    $input->phone,
                ));
            },
        );
    }
}
