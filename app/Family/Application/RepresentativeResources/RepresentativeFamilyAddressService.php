<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\ActivateFamilyAddress;
use App\Family\Application\AssignRepresentativeAddress;
use App\Family\Application\AssignStudentAddress;
use App\Family\Application\CreateFamilyAddress;
use App\Family\Application\DeactivateFamilyAddress;
use App\Family\Application\Dto\ActivateFamilyAddressInput;
use App\Family\Application\Dto\AssignRepresentativeAddressInput;
use App\Family\Application\Dto\AssignStudentAddressInput;
use App\Family\Application\Dto\CreateFamilyAddressInput;
use App\Family\Application\Dto\DeactivateFamilyAddressInput;
use App\Family\Application\Dto\EndRepresentativeAddressAssignmentInput;
use App\Family\Application\Dto\EndStudentAddressAssignmentInput;
use App\Family\Application\Dto\FamilyAddressOutput;
use App\Family\Application\Dto\RepresentativeAddressAssignmentOutput;
use App\Family\Application\Dto\StudentAddressAssignmentOutput;
use App\Family\Application\Dto\UpdateFamilyAddressInput;
use App\Family\Application\EndRepresentativeAddressAssignment;
use App\Family\Application\EndStudentAddressAssignment;
use App\Family\Application\UpdateFamilyAddress;
use DateTimeImmutable;

final readonly class RepresentativeFamilyAddressService
{
    public function __construct(
        private RepresentativeFamilyResourceAuthorization $authorization,
        private CreateFamilyAddress $createAddress,
        private UpdateFamilyAddress $updateAddress,
        private ActivateFamilyAddress $activateAddress,
        private DeactivateFamilyAddress $deactivateAddress,
        private AssignRepresentativeAddress $assignRepresentativeAddress,
        private EndRepresentativeAddressAssignment $endRepresentativeAddress,
        private AssignStudentAddress $assignStudentAddress,
        private EndStudentAddressAssignment $endStudentAddress,
    ) {
    }

    public function create(
        int $expectedFamilyId,
        string $label,
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        ?string $latitude,
        ?string $longitude,
    ): FamilyAddressOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);

        return $this->createAddress->handle(new CreateFamilyAddressInput(
            $authorized->portalResources->familyId,
            $label,
            $mainStreet,
            $streetNumber,
            $secondaryStreet,
            $sector,
            $reference,
            $latitude,
            $longitude,
        ));
    }

    public function update(
        int $expectedFamilyId,
        int $familyAddressId,
        string $label,
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        ?string $latitude,
        ?string $longitude,
    ): FamilyAddressOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->addresses, $familyAddressId);
        $this->authorization->assertAddressCanChange($authorized, $familyAddressId);

        return $this->updateAddress->handle(new UpdateFamilyAddressInput(
            $authorized->portalResources->familyId,
            $familyAddressId,
            $label,
            $mainStreet,
            $streetNumber,
            $secondaryStreet,
            $sector,
            $reference,
            $latitude,
            $longitude,
        ));
    }

    public function activate(int $expectedFamilyId, int $familyAddressId): FamilyAddressOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->addresses, $familyAddressId);

        return $this->activateAddress->handle(new ActivateFamilyAddressInput(
            $authorized->portalResources->familyId,
            $familyAddressId,
        ));
    }

    public function deactivate(int $expectedFamilyId, int $familyAddressId): FamilyAddressOutput
    {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->addresses, $familyAddressId);
        $this->authorization->assertAddressCanChange($authorized, $familyAddressId);

        return $this->deactivateAddress->handle(new DeactivateFamilyAddressInput(
            $authorized->portalResources->familyId,
            $familyAddressId,
        ));
    }

    public function assignSelf(
        int $expectedFamilyId,
        int $familyAddressId,
        DateTimeImmutable $startedAt,
    ): RepresentativeAddressAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireResource($authorized->portalResources->addresses, $familyAddressId, true);

        return $this->assignRepresentativeAddress->handle(new AssignRepresentativeAddressInput(
            $authorized->portalResources->familyId,
            $authorized->representativeId,
            $familyAddressId,
            $startedAt,
        ));
    }

    public function endSelf(
        int $expectedFamilyId,
        int $assignmentId,
        DateTimeImmutable $endedAt,
    ): RepresentativeAddressAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireActiveAssignment(
            $authorized->portalResources->ownRepresentativeAddressAssignments,
            $assignmentId,
        );

        return $this->endRepresentativeAddress->handle(new EndRepresentativeAddressAssignmentInput(
            $authorized->portalResources->familyId,
            $authorized->representativeId,
            $endedAt,
        ));
    }

    public function assignStudent(
        int $expectedFamilyId,
        int $studentId,
        int $familyAddressId,
        DateTimeImmutable $startedAt,
    ): StudentAddressAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $this->authorization->requireStudent($authorized, $studentId);
        $this->authorization->requireResource($authorized->portalResources->addresses, $familyAddressId, true);

        return $this->assignStudentAddress->handle(new AssignStudentAddressInput(
            $authorized->portalResources->familyId,
            $studentId,
            $familyAddressId,
            $startedAt,
        ));
    }

    public function endStudent(
        int $expectedFamilyId,
        int $assignmentId,
        DateTimeImmutable $endedAt,
    ): StudentAddressAssignmentOutput {
        $authorized = $this->authorization->resolveExpected($expectedFamilyId);
        $assignment = $this->authorization->requireActiveAssignment(
            $authorized->portalResources->studentAddressAssignments,
            $assignmentId,
        );
        $this->authorization->requireStudent($authorized, $assignment->studentId);

        return $this->endStudentAddress->handle(new EndStudentAddressAssignmentInput(
            $authorized->portalResources->familyId,
            $assignment->studentId,
            $endedAt,
        ));
    }
}
