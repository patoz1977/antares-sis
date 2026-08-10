<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId;

final readonly class GetAuthenticatedRepresentative
{
    public function __construct(
        private GetAuthenticatedUser $getAuthenticatedUser,
        private RepresentativeRepository $representatives,
    ) {
    }

    public function handle(): ?AuthenticatedRepresentative
    {
        $user = $this->getAuthenticatedUser->handle();
        if ($user === null) {
            return null;
        }

        $personId = new PersonId($user->personId);
        $representative = $this->representatives->findByPersonId($personId);
        if ($representative === null) {
            return null;
        }

        $representativeId = $representative->id();
        if ($representativeId === null || !$representative->personId()->equals($personId)) {
            throw new InvalidPersistedRepresentativeResult(
                'Representative repository returned an invalid persisted identity.'
            );
        }

        return new AuthenticatedRepresentative(
            $user->id,
            $user->personId,
            $representativeId->value(),
            $user->loginIdentifier,
        );
    }
}
