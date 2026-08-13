<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources;

use App\Family\Application\GetFamilyMembership;
use App\Family\Application\GetFamilyResources;
use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyResourcesOutput;
use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyStudentOption;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyAddressModificationNotAllowed;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextChanged;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyResourceUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilySelectionRequired;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyStudentUnavailable;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\GetPerson;
use App\Student\Application\GetStudent;

final readonly class RepresentativeFamilyResourceAuthorization
{
    public function __construct(
        private ResolveFamilyContext $resolveFamilyContext,
        private GetFamilyResources $getFamilyResources,
        private GetFamilyMembership $getFamilyMembership,
        private GetStudent $getStudent,
        private GetPerson $getPerson,
    ) {
    }

    public function resolve(): AuthorizedRepresentativeFamilyResources
    {
        $access = $this->resolveFamilyContext->handle();
        if ($access === null || $access->authorizedFamilies === []) {
            throw new RepresentativeFamilyContextUnavailable('Representative Family context is unavailable.');
        }
        if ($access->context === null || $access->requiresSelection) {
            throw new RepresentativeFamilySelectionRequired('Representative Family selection is required.');
        }

        $context = $access->context;
        $resources = $this->getFamilyResources->handle($context->familyId);
        $family = $this->getFamilyMembership->handle($context->familyId);
        $students = [];
        $studentIds = [];
        foreach ($family->students as $membership) {
            if (!$membership->isActive) {
                continue;
            }

            $student = $this->getStudent->handle($membership->studentId);
            $person = $this->getPerson->handle($student->personId);
            $displayName = trim(implode(' ', array_filter([
                $person->firstName,
                $person->middleName,
                $person->firstSurname,
                $person->secondSurname,
            ], static fn (?string $part): bool => $part !== null && $part !== '')));
            if ($displayName === '') {
                throw new PersonNotFound('Authorized Student Person was not found.');
            }

            $students[] = new RepresentativeFamilyStudentOption($student->id, $displayName);
            $studentIds[$student->id] = true;
        }

        $portalResources = new RepresentativeFamilyResourcesOutput(
            $context->familyId,
            $context->familyDisplayName,
            count($access->authorizedFamilies) > 1,
            $students,
            $resources->addresses,
            array_values(array_filter(
                $resources->representativeAddressAssignments,
                static fn (object $assignment): bool =>
                    $assignment->representativeId === $context->representativeId,
            )),
            $this->studentAssignments($resources->studentAddressAssignments, $studentIds),
            $resources->emergencyContacts,
            $this->studentAssignments($resources->emergencyContactAssignments, $studentIds),
            $resources->authorizedPickups,
            $this->studentAssignments($resources->authorizedPickupAssignments, $studentIds),
        );

        return new AuthorizedRepresentativeFamilyResources(
            $context->representativeId,
            $resources,
            $portalResources,
        );
    }

    public function resolveExpected(int $expectedFamilyId): AuthorizedRepresentativeFamilyResources
    {
        $authorized = $this->resolve();
        if ($authorized->portalResources->familyId !== $expectedFamilyId) {
            throw new RepresentativeFamilyContextChanged('Representative Family context changed.');
        }

        return $authorized;
    }

    public function requireStudent(AuthorizedRepresentativeFamilyResources $authorized, int $studentId): void
    {
        foreach ($authorized->portalResources->students as $student) {
            if ($student->studentId === $studentId) {
                return;
            }
        }

        throw new RepresentativeFamilyStudentUnavailable('Selected resource is unavailable.');
    }

    public function requireResource(array $resources, int $resourceId, bool $activeOnly = false): object
    {
        foreach ($resources as $resource) {
            if ($resource->id === $resourceId && (!$activeOnly || $resource->status === 'ACTIVE')) {
                return $resource;
            }
        }

        throw new RepresentativeFamilyResourceUnavailable('Selected resource is unavailable.');
    }

    public function requireActiveAssignment(array $assignments, int $assignmentId): object
    {
        foreach ($assignments as $assignment) {
            if ($assignment->id === $assignmentId && $assignment->isActive) {
                return $assignment;
            }
        }

        throw new RepresentativeFamilyResourceUnavailable('Selected resource is unavailable.');
    }

    public function assertAddressCanChange(
        AuthorizedRepresentativeFamilyResources $authorized,
        int $familyAddressId,
    ): void {
        foreach ($authorized->completeResources->representativeAddressAssignments as $assignment) {
            if ($assignment->isActive
                && $assignment->familyAddressId === $familyAddressId
                && $assignment->representativeId !== $authorized->representativeId
            ) {
                throw new RepresentativeFamilyAddressModificationNotAllowed(
                    'This address cannot be changed from your account.'
                );
            }
        }
    }

    /** @param array<int, true> $studentIds */
    private function studentAssignments(array $assignments, array $studentIds): array
    {
        return array_values(array_filter(
            $assignments,
            static fn (object $assignment): bool => isset($studentIds[$assignment->studentId]),
        ));
    }
}
