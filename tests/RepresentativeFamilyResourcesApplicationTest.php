<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\GetFamilyResources;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyAddressModificationNotAllowed;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextChanged;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyResourceUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilySelectionRequired;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyStudentUnavailable;
use App\Family\Application\RepresentativeResources\GetRepresentativeFamilyResources;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyAddressService;
use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\TestRunner;

function registerRepresentativeFamilyResourcesApplicationTests(TestRunner $runner): void
{
    $runner->add('Representative Resources Application fails closed without actor Family or selection', function (): void {
        foreach ([
            [representativeFamilyResourcesFixture(withUser: false, withFamily: false), RepresentativeFamilyContextUnavailable::class],
            [representativeFamilyResourcesFixture(withFamily: false), RepresentativeFamilyContextUnavailable::class],
            [representativeFamilyResourcesFixture(withSecondFamily: true), RepresentativeFamilySelectionRequired::class],
        ] as [$fixture, $exception]) {
            assertThrows($fixture['getResources']->handle(...), $exception);
            assertSameValue(0, $fixture['families']->saveCalls());
        }
    });

    $runner->add('Representative Resources Application returns only authorized safe read projection', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        $output = $fixture['getResources']->handle();

        assertSameValue(500, $output->familyId);
        assertSameValue('Family <A>', $output->familyDisplayName);
        assertSameValue(false, $output->canChangeFamily);
        assertSameValue([301], array_map(static fn (object $student): int => $student->studentId, $output->students));
        assertSameValue(
            ['<script>Student</script> & One'],
            array_map(static fn (object $student): string => $student->displayName, $output->students),
        );
        assertSameValue([81], array_map(
            static fn (object $assignment): int => $assignment->id,
            $output->ownRepresentativeAddressAssignments,
        ));
        assertSameValue([], $output->studentAddressAssignments);
        assertSameValue([], $output->emergencyContactAssignments);
        assertSameValue([], $output->authorizedPickupAssignments);
        assertSameValue(false, property_exists($output, 'representativeAddressAssignments'));
        assertSameValue(false, property_exists($output, 'representatives'));
        assertSameValue(false, property_exists($output, 'familyStudents'));
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
    });

    $runner->add('Representative Resources Application binds every command to freshly resolved Family', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        $fixture['getResources']->handle();
        $fixture['select']->handle(600);
        $before = $fixture['families']->saveCalls();

        assertThrows(
            static fn () => $fixture['addressService']->activate(500, 11),
            RepresentativeFamilyContextChanged::class,
        );
        assertSameValue($before, $fixture['families']->saveCalls());

        $fixture['addressService']->update(
            600,
            11,
            'Family B authorized address',
            'B street',
            null,
            null,
            null,
            null,
            null,
            null,
        );
        assertSameValue(
            'Address 11',
            familyResourcesFindById((new GetFamilyResources($fixture['families']))->handle(500)->addresses, 11)->label,
        );
        assertSameValue(
            'Family B authorized address',
            familyResourcesFindById((new GetFamilyResources($fixture['families']))->handle(600)->addresses, 11)->label,
        );
    });

    $runner->add('Representative Resources Application rejects stale selected Family before mutation', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        familyContextEndRepresentativeMembership($fixture['families'], 500, 33);
        $before = $fixture['families']->saveCalls();

        assertThrows(
            static fn () => $fixture['addressService']->activate(500, 11),
            RepresentativeFamilyContextChanged::class,
        );
        assertSameValue($before, $fixture['families']->saveCalls());
        assertSameValue(600, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Representative Address Application derives self and denies other Representative authority', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        $assignment = $fixture['addressService']->assignSelf(
            500,
            11,
            representativeFamilyResourcesTime('2026-08-12 09:00:00'),
        );
        assertSameValue(33, $assignment->representativeId);

        $before = $fixture['families']->saveCalls();
        assertThrows(
            static fn () => $fixture['addressService']->endSelf(
                500,
                85,
                representativeFamilyResourcesTime('2026-08-12 10:00:00'),
            ),
            RepresentativeFamilyResourceUnavailable::class,
        );
        assertSameValue($before, $fixture['families']->saveCalls());

        $ended = $fixture['addressService']->endSelf(
            500,
            $assignment->id,
            representativeFamilyResourcesTime('2026-08-12 10:00:00'),
        );
        assertSameValue(false, $ended->isActive);

        $method = new ReflectionMethod(RepresentativeFamilyAddressService::class, 'assignSelf');
        assertSameValue(
            ['expectedFamilyId', 'familyAddressId', 'startedAt'],
            array_map(static fn (object $parameter): string => $parameter->getName(), $method->getParameters()),
        );
    });

    $runner->add('Representative Address Application delegates complete authorized resource lifecycle', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        $service = $fixture['addressService'];
        $created = $service->create(
            500,
            'Created address',
            'Created street',
            null,
            null,
            null,
            null,
            null,
            null,
        );
        $updated = $service->update(
            500,
            $created->id,
            'Updated address',
            'Updated street',
            null,
            null,
            null,
            null,
            null,
            null,
        );
        assertSameValue('Updated address', $updated->label);
        assertSameValue('INACTIVE', $service->deactivate(500, $created->id)->status);
        assertSameValue('ACTIVE', $service->activate(500, $created->id)->status);
    });

    $runner->add('Representative Address Application protects Address used by another Representative', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        foreach (['update', 'deactivate'] as $method) {
            $before = $fixture['families']->saveCalls();
            $operation = $method === 'update'
                ? static fn () => $fixture['addressService']->update(
                    500,
                    12,
                    'Denied',
                    'Denied street',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                )
                : static fn () => $fixture['addressService']->deactivate(500, 12);
            assertThrows($operation, RepresentativeFamilyAddressModificationNotAllowed::class);
            assertSameValue($before, $fixture['families']->saveCalls());
        }

        $fixture['addressService']->assignSelf(
            500,
            12,
            representativeFamilyResourcesTime('2026-08-12 11:00:00'),
        );
        assertThrows(
            static fn () => $fixture['addressService']->deactivate(500, 12),
            RepresentativeFamilyAddressModificationNotAllowed::class,
        );
    });

    $runner->add('Representative Student resource Application allows only active related Students', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        $assignment = $fixture['addressService']->assignStudent(
            500,
            301,
            11,
            representativeFamilyResourcesTime('2026-08-12 12:00:00'),
        );
        assertSameValue(301, $assignment->studentId);

        foreach ([302, 401] as $studentId) {
            $before = $fixture['families']->saveCalls();
            assertThrows(
                static fn () => $fixture['addressService']->assignStudent(
                    500,
                    $studentId,
                    11,
                    representativeFamilyResourcesTime('2026-08-12 13:00:00'),
                ),
                RepresentativeFamilyStudentUnavailable::class,
            );
            assertSameValue($before, $fixture['families']->saveCalls());
        }

        $fixture['addressService']->endStudent(
            500,
            $assignment->id,
            representativeFamilyResourcesTime('2026-08-12 14:00:00'),
        );
    });

    $runner->add('Representative Resources Application denies inaccessible resources and assignment history', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        foreach ([
            static fn () => $fixture['addressService']->activate(500, 999),
            static fn () => $fixture['addressService']->endStudent(
                500,
                82,
                representativeFamilyResourcesTime('2026-08-12 15:00:00'),
            ),
            static fn () => $fixture['emergencyService']->end(
                500,
                83,
                representativeFamilyResourcesTime('2026-08-12 15:00:00'),
            ),
            static fn () => $fixture['pickupService']->end(
                500,
                84,
                representativeFamilyResourcesTime('2026-08-12 15:00:00'),
            ),
        ] as $operation) {
            $before = $fixture['families']->saveCalls();
            assertThrows($operation, RepresentativeFamilyResourceUnavailable::class);
            assertSameValue($before, $fixture['families']->saveCalls());
        }
    });

    $runner->add('Representative Emergency Contact Application delegates complete authorized lifecycle', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        $service = $fixture['emergencyService'];
        $created = $service->create(500, 'Created contact', 201, 'mobile', null, null, null);
        $updated = $service->update(500, $created->id, 'Updated contact', 201, 'mobile', null, null, null);
        assertSameValue('Updated contact', $updated->names);
        $service->deactivate(500, $created->id);
        $service->activate(500, $created->id);
        $assignment = $service->assign(
            500,
            $created->id,
            301,
            2,
            representativeFamilyResourcesTime('2026-08-12 16:00:00'),
        );
        $ended = $service->end(
            500,
            $assignment->id,
            representativeFamilyResourcesTime('2026-08-12 17:00:00'),
        );
        assertSameValue(false, $ended->isActive);
    });

    $runner->add('Representative Emergency and Pickup Application deny unauthorized Students before save', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        foreach ([
            static fn () => $fixture['emergencyService']->assign(
                500,
                21,
                302,
                null,
                representativeFamilyResourcesTime('2026-08-12 17:30:00'),
            ),
            static fn () => $fixture['pickupService']->assign(
                500,
                31,
                401,
                representativeFamilyResourcesTime('2026-08-12 17:30:00'),
            ),
        ] as $operation) {
            $before = $fixture['families']->saveCalls();
            assertThrows($operation, RepresentativeFamilyStudentUnavailable::class);
            assertSameValue($before, $fixture['families']->saveCalls());
        }
    });

    $runner->add('Representative Authorized Pickup Application delegates complete authorized lifecycle', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        $service = $fixture['pickupService'];
        $created = $service->create(500, 'Created pickup', 201, 'mobile', null, null, null, null);
        $updated = $service->update(500, $created->id, 'Updated pickup', 201, 'mobile', null, 9, 'ID-1', null);
        assertSameValue('Updated pickup', $updated->names);
        $service->deactivate(500, $created->id);
        $service->activate(500, $created->id);
        $assignment = $service->assign(
            500,
            $created->id,
            301,
            representativeFamilyResourcesTime('2026-08-12 18:00:00'),
        );
        $ended = $service->end(
            500,
            $assignment->id,
            representativeFamilyResourcesTime('2026-08-12 19:00:00'),
        );
        assertSameValue(false, $ended->isActive);
    });

    $runner->add('Representative Resources authorization layer stays Application-only and reuses Phase 4 cases', function (): void {
        $source = representativeFamilyResourcesApplicationSource();
        foreach (['PDO', '\\Infrastructure\\', '\\Http\\', 'Controller', 'Request', 'Response',
            'SessionManager', '\\Views\\', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        foreach (['ResolveFamilyContext', 'GetFamilyResources', 'GetFamilyMembership', 'GetStudent', 'GetPerson',
            'CreateFamilyAddress', 'UpdateFamilyAddress', 'AssignRepresentativeAddress', 'AssignStudentAddress',
            'CreateFamilyEmergencyContact', 'AssignEmergencyContact', 'CreateFamilyAuthorizedPickup',
            'AssignAuthorizedPickup'] as $required) {
            assertSameValue(true, str_contains($source, $required));
        }
        assertSameValue(0, count((new ReflectionMethod(GetRepresentativeFamilyResources::class, 'handle'))->getParameters()));
    });
}

function representativeFamilyResourcesApplicationSource(): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/app/Family/Application/RepresentativeResources'
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        $files,
    ));
}
