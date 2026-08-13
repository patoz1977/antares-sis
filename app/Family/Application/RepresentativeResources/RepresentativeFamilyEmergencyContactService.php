<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\ActivateFamilyEmergencyContact;
use App\Family\Application\AssignEmergencyContact;
use App\Family\Application\CreateFamilyEmergencyContact;
use App\Family\Application\DeactivateFamilyEmergencyContact;
use App\Family\Application\Dto\ActivateFamilyEmergencyContactInput;
use App\Family\Application\Dto\AssignEmergencyContactInput;
use App\Family\Application\Dto\CreateFamilyEmergencyContactInput;
use App\Family\Application\Dto\DeactivateFamilyEmergencyContactInput;
use App\Family\Application\Dto\EmergencyContactAssignmentOutput;
use App\Family\Application\Dto\EndEmergencyContactAssignmentInput;
use App\Family\Application\Dto\FamilyEmergencyContactOutput;
use App\Family\Application\Dto\UpdateFamilyEmergencyContactInput;
use App\Family\Application\EndEmergencyContactAssignment;
use App\Family\Application\UpdateFamilyEmergencyContact;
use DateTimeImmutable;

final readonly class RepresentativeFamilyEmergencyContactService
{
    public function __construct(
        private RepresentativeFamilyResourceAuthorization $authorization,
        private CreateFamilyEmergencyContact $createContact,
        private UpdateFamilyEmergencyContact $updateContact,
        private ActivateFamilyEmergencyContact $activateContact,
        private DeactivateFamilyEmergencyContact $deactivateContact,
        private AssignEmergencyContact $assignContact,
        private EndEmergencyContactAssignment $endContact,
    ) {
    }

    public function create(
        int $expectedFamilyId,
        string $names,
        int $relationshipTypeId,
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
    ): FamilyEmergencyContactOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);

        return $this->createContact->handle(new CreateFamilyEmergencyContactInput(
            $authorized->portalResources->familyId,
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $phone,
            $email,
            $observations,
        ));
    }

    public function update(
        int $expectedFamilyId,
        int $contactId,
        string $names,
        int $relationshipTypeId,
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
    ): FamilyEmergencyContactOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->emergencyContacts, $contactId);

        return $this->updateContact->handle(new UpdateFamilyEmergencyContactInput(
            $authorized->portalResources->familyId,
            $contactId,
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $phone,
            $email,
            $observations,
        ));
    }

    public function activate(int $expectedFamilyId, int $contactId): FamilyEmergencyContactOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->emergencyContacts, $contactId);

        return $this->activateContact->handle(new ActivateFamilyEmergencyContactInput(
            $authorized->portalResources->familyId,
            $contactId,
        ));
    }

    public function deactivate(int $expectedFamilyId, int $contactId): FamilyEmergencyContactOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->emergencyContacts, $contactId);

        return $this->deactivateContact->handle(new DeactivateFamilyEmergencyContactInput(
            $authorized->portalResources->familyId,
            $contactId,
        ));
    }

    public function assign(
        int $expectedFamilyId,
        int $contactId,
        int $studentId,
        ?int $priority,
        DateTimeImmutable $startedAt,
    ): EmergencyContactAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->emergencyContacts, $contactId, true);
        $this->authorization->requireStudent($authorized, $studentId);

        return $this->assignContact->handle(new AssignEmergencyContactInput(
            $authorized->portalResources->familyId,
            $contactId,
            $studentId,
            $priority,
            $startedAt,
        ));
    }

    public function end(
        int $expectedFamilyId,
        int $assignmentId,
        DateTimeImmutable $endedAt,
    ): EmergencyContactAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $assignment = $this->authorization->requireActiveAssignment(
            $authorized->portalResources->emergencyContactAssignments,
            $assignmentId,
        );
        $this->authorization->requireStudent($authorized, $assignment->studentId);

        return $this->endContact->handle(new EndEmergencyContactAssignmentInput(
            $authorized->portalResources->familyId,
            $assignment->familyEmergencyContactId,
            $assignment->studentId,
            $endedAt,
        ));
    }
}
