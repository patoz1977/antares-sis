<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

use App\Enrollment\Application\RepresentativePortal\Dto\UpdateStudentPersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable;
use App\Enrollment\Application\RepresentativePortal\Support\PersonPersistenceSupport;
use App\IdentityAccess\Application\Contract\Clock;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use Core\Application\TransactionRunner;

final readonly class UpdateAuthorizedStudentPersonalInformation
{
    public function __construct(
        private RepresentativeEnrollmentPortalAuthorization $authorization,
        private PersonRepository $persons,
        private Clock $clock,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(UpdateStudentPersonalInformationInput $input): PersonOutput
    {
        return $this->transactions->run(function () use ($input): PersonOutput {
            $context = $this->authorization->resolveMutationContext(
                $input->expectedFamilyId,
                $input->expectedAcademicPeriodId,
                $input->studentId,
            );
            if ($context->studentPersonId === null) {
                throw new RepresentativeEnrollmentStudentUnavailable('Selected Student is unavailable.');
            }
            $personId = new PersonId($context->studentPersonId);
            $person = $this->persons->findByIdForUpdate($personId);
            if ($person === null || $person->id()?->equals($personId) !== true) {
                throw new RepresentativeEnrollmentStudentUnavailable('Selected Student is unavailable.');
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
