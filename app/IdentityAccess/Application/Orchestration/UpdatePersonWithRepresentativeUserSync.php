<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Orchestration;

use App\IdentityAccess\Application\Exception\InvalidPersistedUserResult;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use Core\Application\TransactionRunner;
use DateTimeImmutable;

final readonly class UpdatePersonWithRepresentativeUserSync
{
    public function __construct(
        private UpdatePerson $updatePerson,
        private PersonRepository $persons,
        private UserRepository $users,
        private RepresentativeRepository $representatives,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(
        UpdatePersonInput $input,
        DateTimeImmutable $today,
    ): PersonOutput {
        return $this->transactions->run(function () use ($input, $today): PersonOutput {
            $personId = new PersonId($input->personId);
            $person = $this->persons->findById($personId);
            if ($person === null) {
                throw new PersonNotFound('Person was not found.');
            }

            $representative = $this->representatives->findByPersonId(
                new RepresentativePersonId($personId->value())
            );
            $userPersonId = new UserPersonId($personId->value());
            $user = $this->users->findByPersonId($userPersonId);

            if ($representative !== null
                && ($input->email === null || trim($input->email) === '')
            ) {
                throw new RepresentativeRequiresContactEmail(
                    'Representative requires a Person contact email.'
                );
            }

            if ($representative === null || $user === null) {
                return $this->updatePerson->handle($input, $today);
            }

            if ($input->documentTypeId === null
                || $input->documentNumber === null
                || trim($input->documentNumber) === ''
            ) {
                throw new RepresentativeUserRequiresIdentification(
                    'Representative User requires complete Person identification.'
                );
            }

            $userId = $user->id();
            if ($userId === null) {
                throw new InvalidPersistedUserResult(
                    'Representative User does not have a persisted identity.'
                );
            }

            $loginIdentifier = new LoginIdentifier($input->documentNumber);
            $loginChanged = $loginIdentifier->value() !== $user->loginIdentifier()->value();
            if ($loginChanged) {
                $owner = $this->users->findByLoginIdentifier($loginIdentifier);
                $ownerId = $owner?->id();
                if ($owner !== null
                    && ($ownerId === null || $ownerId->value() !== $userId->value())
                ) {
                    throw new RepresentativeLoginIdentifierAlreadyUsed(
                        'Representative login identifier is already in use.'
                    );
                }
            }

            $result = $this->updatePerson->handle($input, $today);

            if ($loginChanged) {
                $user->changeLoginIdentifier($loginIdentifier);
                $persisted = $this->users->save($user);
                $persistedId = $persisted->id();
                if ($persistedId === null || $persistedId->value() !== $userId->value()) {
                    throw new InvalidPersistedUserResult(
                        'User repository returned an invalid persisted identity.'
                    );
                }
            }

            return $result;
        });
    }
}
