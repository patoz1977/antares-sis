<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativePersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Support\PersonPersistenceSupport;
use App\IdentityAccess\Application\Contract\Clock;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use Core\Application\TransactionRunner;

final readonly class UpdateAuthenticatedRepresentativePersonalInformation
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private PersonRepository $persons,
        private Clock $clock,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateRepresentativePersonalInformationInput $input): PersonOutput
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

            $person->updateIdentity(
                new PersonalName(
                    $input->firstName,
                    $input->middleName,
                    $input->firstSurname,
                    $input->secondSurname,
                ),
                $person->identification(),
                $input->birthDate,
                $person->sexId(),
                $input->maritalStatusId,
                $input->educationLevelId,
                $this->clock->now(),
            );

            return PersonPersistenceSupport::save($this->persons, $person);
        });
    }
}
