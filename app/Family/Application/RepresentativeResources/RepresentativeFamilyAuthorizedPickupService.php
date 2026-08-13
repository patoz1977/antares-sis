<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\ActivateFamilyAuthorizedPickup;
use App\Family\Application\AssignAuthorizedPickup;
use App\Family\Application\CreateFamilyAuthorizedPickup;
use App\Family\Application\DeactivateFamilyAuthorizedPickup;
use App\Family\Application\Dto\ActivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\AssignAuthorizedPickupInput;
use App\Family\Application\Dto\AuthorizedPickupAssignmentOutput;
use App\Family\Application\Dto\CreateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\DeactivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\EndAuthorizedPickupAssignmentInput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Family\Application\Dto\UpdateFamilyAuthorizedPickupInput;
use App\Family\Application\EndAuthorizedPickupAssignment;
use App\Family\Application\UpdateFamilyAuthorizedPickup;
use DateTimeImmutable;

final readonly class RepresentativeFamilyAuthorizedPickupService
{
    public function __construct(
        private RepresentativeFamilyResourceAuthorization $authorization,
        private CreateFamilyAuthorizedPickup $createPickup,
        private UpdateFamilyAuthorizedPickup $updatePickup,
        private ActivateFamilyAuthorizedPickup $activatePickup,
        private DeactivateFamilyAuthorizedPickup $deactivatePickup,
        private AssignAuthorizedPickup $assignPickup,
        private EndAuthorizedPickupAssignment $endPickup,
    ) {
    }

    public function create(
        int $expectedFamilyId,
        string $names,
        int $relationshipTypeId,
        string $mobilePhone,
        ?string $phone,
        ?int $documentTypeId,
        ?string $documentNumber,
        ?string $observations,
    ): FamilyAuthorizedPickupOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);

        return $this->createPickup->handle(new CreateFamilyAuthorizedPickupInput(
            $authorized->portalResources->familyId,
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $phone,
            $documentTypeId,
            $documentNumber,
            $observations,
        ));
    }

    public function update(
        int $expectedFamilyId,
        int $pickupId,
        string $names,
        int $relationshipTypeId,
        string $mobilePhone,
        ?string $phone,
        ?int $documentTypeId,
        ?string $documentNumber,
        ?string $observations,
    ): FamilyAuthorizedPickupOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->authorizedPickups, $pickupId);

        return $this->updatePickup->handle(new UpdateFamilyAuthorizedPickupInput(
            $authorized->portalResources->familyId,
            $pickupId,
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $phone,
            $documentTypeId,
            $documentNumber,
            $observations,
        ));
    }

    public function activate(int $expectedFamilyId, int $pickupId): FamilyAuthorizedPickupOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->authorizedPickups, $pickupId);

        return $this->activatePickup->handle(new ActivateFamilyAuthorizedPickupInput(
            $authorized->portalResources->familyId,
            $pickupId,
        ));
    }

    public function deactivate(int $expectedFamilyId, int $pickupId): FamilyAuthorizedPickupOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->authorizedPickups, $pickupId);

        return $this->deactivatePickup->handle(new DeactivateFamilyAuthorizedPickupInput(
            $authorized->portalResources->familyId,
            $pickupId,
        ));
    }

    public function assign(
        int $expectedFamilyId,
        int $pickupId,
        int $studentId,
        DateTimeImmutable $startedAt,
    ): AuthorizedPickupAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->authorizedPickups, $pickupId, true);
        $this->authorization->requireStudent($authorized, $studentId);

        return $this->assignPickup->handle(new AssignAuthorizedPickupInput(
            $authorized->portalResources->familyId,
            $pickupId,
            $studentId,
            $startedAt,
        ));
    }

    public function end(
        int $expectedFamilyId,
        int $assignmentId,
        DateTimeImmutable $endedAt,
    ): AuthorizedPickupAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $assignment = $this->authorization->requireActiveAssignment(
            $authorized->portalResources->authorizedPickupAssignments,
            $assignmentId,
        );
        $this->authorization->requireStudent($authorized, $assignment->studentId);

        return $this->endPickup->handle(new EndAuthorizedPickupAssignmentInput(
            $authorized->portalResources->familyId,
            $assignment->familyAuthorizedPickupId,
            $assignment->studentId,
            $endedAt,
        ));
    }
}
