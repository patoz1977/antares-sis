<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\RepresentativeId;

final readonly class GetAuthorizedFamilies
{
    public function __construct(
        private GetAuthenticatedRepresentative $getAuthenticatedRepresentative,
        private FamilyRepository $families,
    ) {
    }

    public function handle(): ?AuthorizedFamilySet
    {
        $representative = $this->getAuthenticatedRepresentative->handle();
        if ($representative === null) {
            return null;
        }

        $representativeId = new RepresentativeId($representative->representativeId);
        $authorizedFamilies = [];
        $seenFamilyIds = [];
        foreach ($this->families->findActiveByRepresentativeId($representativeId) as $family) {
            $familyId = $family->id();
            if ($familyId === null || isset($seenFamilyIds[$familyId->value()])) {
                throw new InvalidPersistedFamilyResult(
                    'Family repository returned a missing or duplicate persisted identity.'
                );
            }

            $containsRepresentative = false;
            foreach ($family->activeRepresentatives() as $membership) {
                if ($membership->representativeId()->equals($representativeId)) {
                    $containsRepresentative = true;
                    break;
                }
            }
            if (!$containsRepresentative) {
                throw new InvalidPersistedFamilyResult(
                    'Family repository returned a Family without the requested active membership.'
                );
            }

            $seenFamilyIds[$familyId->value()] = true;
            $authorizedFamilies[] = new AuthorizedFamily(
                $familyId->value(),
                $family->displayName()->value(),
            );
        }

        return new AuthorizedFamilySet($representative, $authorizedFamilies);
    }
}
