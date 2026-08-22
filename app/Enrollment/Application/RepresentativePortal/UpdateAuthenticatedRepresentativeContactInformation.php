<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeContactInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Support\PersonPersistenceSupport;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use Core\Application\TransactionRunner;

final readonly class UpdateAuthenticatedRepresentativeContactInformation
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private PersonRepository $persons,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateRepresentativeContactInformationInput $input): PersonOutput
    {
        return $this->transactions->run(function () use ($input): PersonOutput {
            $context = $this->authorization->resolveMutationContext(
                $input->expectedFamilyId,
                $input->expectedAcademicPeriodId,
            );
            $personId = new PersonId($context->representativePersonId);
            $person = $this->persons->findByIdForUpdate($personId);
            if ($person === null || $person->id()?->equals($personId) !== true) {
                throw new RepresentativeEnrollmentContextUnavailable(
                    'Representative Enrollment context is unavailable.'
                );
            }
            if (trim($input->email) === '') {
                throw new RepresentativeRequiresContactEmail(
                    'Representative personal email is required.'
                );
            }

            $person->updateContactInformation(new ContactInformation(
                $input->email,
                $input->mobilePhone,
                $input->landlinePhone,
            ));

            return PersonPersistenceSupport::save($this->persons, $person);
        });
    }
}
