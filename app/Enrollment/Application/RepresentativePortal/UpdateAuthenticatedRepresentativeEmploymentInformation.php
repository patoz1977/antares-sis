<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEmploymentInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativePersistenceSupport;
use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\RepresentativeId;
use Core\Application\TransactionRunner;

final readonly class UpdateAuthenticatedRepresentativeEmploymentInformation
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private RepresentativeRepository $representatives,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateRepresentativeEmploymentInformationInput $input): RepresentativeOutput
    {
        return $this->transactions->run(function () use ($input): RepresentativeOutput {
            $context = $this->authorization->resolveMutationContext(
                $input->expectedFamilyId,
                $input->expectedAcademicPeriodId,
            );
            $representativeId = new RepresentativeId($context->representativeId);
            $representative = $this->representatives->findByIdForUpdate($representativeId);
            if ($representative === null
                || $representative->id()?->equals($representativeId) !== true
                || $representative->personId()->value() !== $context->representativePersonId
            ) {
                throw new RepresentativeEnrollmentContextUnavailable(
                    'Representative Enrollment context is unavailable.'
                );
            }

            $employment = new EmploymentInformation(
                $input->occupation,
                $input->companyName,
                $input->position,
                $input->workPhone,
                $input->workEmail,
            );
            $representative->replaceEmploymentInformation($employment->isEmpty() ? null : $employment);

            return RepresentativePersistenceSupport::save($this->representatives, $representative);
        });
    }
}
