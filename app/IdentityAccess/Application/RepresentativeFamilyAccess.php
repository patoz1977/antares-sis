<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use InvalidArgumentException;

final readonly class RepresentativeFamilyAccess
{
    /** @param list<AuthorizedFamily> $authorizedFamilies */
    public function __construct(
        public AuthenticatedRepresentative $representative,
        public array $authorizedFamilies,
        public ?FamilyContext $context,
        public bool $requiresSelection,
    ) {
        $familyCount = count($authorizedFamilies);
        if ($familyCount === 0 && ($context !== null || $requiresSelection)) {
            throw new InvalidArgumentException('Empty Family access cannot select or require a Family.');
        }
        if ($familyCount === 1 && ($context === null || $requiresSelection)) {
            throw new InvalidArgumentException('Single Family access must resolve its only context.');
        }
        if ($familyCount > 1 && (($context === null) !== $requiresSelection)) {
            throw new InvalidArgumentException('Multiple Family access has an incoherent selection state.');
        }
        if ($context === null) {
            return;
        }

        if ($context->userId !== $representative->userId
            || $context->personId !== $representative->personId
            || $context->representativeId !== $representative->representativeId
        ) {
            throw new InvalidArgumentException('Family context does not belong to the authenticated Representative.');
        }

        foreach ($authorizedFamilies as $family) {
            if ($family->familyId === $context->familyId) {
                return;
            }
        }

        throw new InvalidArgumentException('Selected Family must belong to the authorized set.');
    }
}
