<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class ResolveFamilyContext
{
    public function __construct(
        private GetAuthorizedFamilies $getAuthorizedFamilies,
        private RepresentativeFamilyContextSession $session,
    ) {
    }

    public function handle(): ?RepresentativeFamilyAccess
    {
        $authorized = $this->getAuthorizedFamilies->handle();
        if ($authorized === null) {
            $this->session->clear();

            return null;
        }

        $familyCount = count($authorized->families);
        if ($familyCount === 0) {
            $this->session->clear();

            return new RepresentativeFamilyAccess(
                $authorized->representative,
                [],
                null,
                false,
            );
        }

        if ($familyCount === 1) {
            $family = $authorized->families[0];
            $this->session->select($family->familyId);

            return new RepresentativeFamilyAccess(
                $authorized->representative,
                $authorized->families,
                FamilyContext::from($authorized->representative, $family),
                false,
            );
        }

        $selectedFamilyId = $this->session->selectedFamilyId();
        foreach ($authorized->families as $family) {
            if ($family->familyId === $selectedFamilyId) {
                return new RepresentativeFamilyAccess(
                    $authorized->representative,
                    $authorized->families,
                    FamilyContext::from($authorized->representative, $family),
                    false,
                );
            }
        }

        $this->session->clear();

        return new RepresentativeFamilyAccess(
            $authorized->representative,
            $authorized->families,
            null,
            true,
        );
    }
}
