<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Exception\FamilyContextNotAuthorized;

final readonly class SelectAuthorizedFamily
{
    public function __construct(
        private GetAuthorizedFamilies $getAuthorizedFamilies,
        private RepresentativeFamilyContextSession $session,
    ) {
    }

    public function handle(int $familyId): FamilyContext
    {
        if ($familyId <= 0) {
            throw new FamilyContextNotAuthorized(
                'Requested Family is not authorized for the authenticated Representative.'
            );
        }

        $authorized = $this->getAuthorizedFamilies->handle();
        if ($authorized === null) {
            throw new FamilyContextNotAuthorized(
                'Requested Family is not authorized for the authenticated Representative.'
            );
        }

        foreach ($authorized->families as $family) {
            if ($family->familyId !== $familyId) {
                continue;
            }

            $context = FamilyContext::from($authorized->representative, $family);
            $this->session->select($familyId);

            return $context;
        }

        throw new FamilyContextNotAuthorized(
            'Requested Family is not authorized for the authenticated Representative.'
        );
    }
}
