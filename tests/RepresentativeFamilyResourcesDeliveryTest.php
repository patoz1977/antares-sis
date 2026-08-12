<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\ActivateFamilyAddress;
use App\Family\Application\ActivateFamilyAuthorizedPickup;
use App\Family\Application\ActivateFamilyEmergencyContact;
use App\Family\Application\AssignAuthorizedPickup;
use App\Family\Application\AssignEmergencyContact;
use App\Family\Application\AssignRepresentativeAddress;
use App\Family\Application\AssignStudentAddress;
use App\Family\Application\CreateFamilyAddress;
use App\Family\Application\CreateFamilyAuthorizedPickup;
use App\Family\Application\CreateFamilyEmergencyContact;
use App\Family\Application\DeactivateFamilyAddress;
use App\Family\Application\DeactivateFamilyAuthorizedPickup;
use App\Family\Application\DeactivateFamilyEmergencyContact;
use App\Family\Application\EndAuthorizedPickupAssignment;
use App\Family\Application\EndEmergencyContactAssignment;
use App\Family\Application\EndRepresentativeAddressAssignment;
use App\Family\Application\EndStudentAddressAssignment;
use App\Family\Application\GetFamilyMembership;
use App\Family\Application\GetFamilyResources;
use App\Family\Application\UpdateFamilyAddress;
use App\Family\Application\UpdateFamilyAuthorizedPickup;
use App\Family\Application\UpdateFamilyEmergencyContact;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyResourceStatus;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\RepresentativeAddressAssignment;
use App\Family\Domain\StudentAddressAssignment;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupAssignmentId;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\EmergencyContactAssignmentId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeAddressAssignmentId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId;
use App\Family\Http\FamilyResourceFormOption;
use App\Family\Http\FamilyResourceFormOptions;
use App\Family\Http\FamilyResourceFormOptionsProvider;
use App\Family\Http\RepresentativeFamilyResourceController;
use App\IdentityAccess\Http\RepresentativePortalController;
use App\Person\Application\GetPerson;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\PersonId as PersonIdentity;
use App\Person\Domain\ValueObject\PersonalName;
use App\Student\Application\GetStudent;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Domain\ValueObject\StudentId as StudentIdentity;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestRunner;

function registerRepresentativeFamilyResourcesDeliveryTests(TestRunner $runner): void
{
    $runner->add('Representative Family Resources exposes exact authenticated routes without admin middleware', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        $approved = [
            "get(\n    '/representative/resources'",
            "'/representative/resources/addresses/create'",
            "'/representative/resources/addresses/update'",
            "'/representative/resources/addresses/activate'",
            "'/representative/resources/addresses/deactivate'",
            "'/representative/resources/address'",
            "'/representative/resources/address/end'",
            "'/representative/resources/students/address'",
            "'/representative/resources/students/address/end'",
            "'/representative/resources/emergency-contacts/create'",
            "'/representative/resources/emergency-contacts/update'",
            "'/representative/resources/emergency-contacts/activate'",
            "'/representative/resources/emergency-contacts/deactivate'",
            "'/representative/resources/emergency-contacts/assign'",
            "'/representative/resources/emergency-contacts/end'",
            "'/representative/resources/authorized-pickups/create'",
            "'/representative/resources/authorized-pickups/update'",
            "'/representative/resources/authorized-pickups/activate'",
            "'/representative/resources/authorized-pickups/deactivate'",
            "'/representative/resources/authorized-pickups/assign'",
            "'/representative/resources/authorized-pickups/end'",
        ];
        foreach ($approved as $route) {
            deliveryAssertContains($route, str_replace("\r\n", "\n", $routes));
        }
        assertSameValue(21, substr_count($routes, "'/representative/resources"));
        assertSameValue(21, substr_count($routes, '[$representativeFamilyResourceController,'));

        $start = strpos($routes, "\$router->get(\n    '/representative/resources'");
        $end = strpos($routes, "\$router->get('/persons'", is_int($start) ? $start : 0);
        $portalRoutes = is_int($start) && is_int($end) ? substr($routes, $start, $end - $start) : '';
        assertSameValue(21, substr_count($portalRoutes, 'AuthenticationMiddleware::class'));
        assertSameValue(false, str_contains($portalRoutes, 'FamilyAdministrationMiddleware'));
        assertSameValue(false, str_contains($portalRoutes, 'PersonAdministrationMiddleware'));
        assertSameValue(21, substr_count($routes, "'/families/resources"));
    });

    $runner->add('Representative Family Resources entry reuses the complete E007 context matrix', function (): void {
        $anonymous = representativeFamilyResourcesFixture(withUser: false, withFamily: false);
        assertSameValue(403, representativeFamilyResourcesStatus($anonymous['controller']));

        $admin = representativeFamilyResourcesFixture(withRepresentative: false, withFamily: false);
        assertSameValue(403, representativeFamilyResourcesStatus($admin['controller']));

        $empty = representativeFamilyResourcesFixture(withFamily: false);
        assertSameValue(403, representativeFamilyResourcesStatus($empty['controller']));

        $single = representativeFamilyResourcesFixture();
        $page = representativeFamilyResourcesGet($single['controller']);
        assertSameValue(200, http_response_code());
        deliveryAssertContains('Manage family resources', $single['portal']->index());
        deliveryAssertContains('Current family: <strong>Family &lt;A&gt;</strong>', $page);
        deliveryAssertContains('&lt;script&gt;Student&lt;/script&gt; &amp; One', $page);
        assertSameValue(false, str_contains($page, '<script>Student</script>'));
        assertSameValue(false, str_contains($page, 'Historical Student'));

        $multiple = representativeFamilyResourcesFixture(withSecondFamily: true);
        assertSameValue('', representativeFamilyResourcesGet($multiple['controller']));
        assertSameValue(302, http_response_code());
        $multiple['select']->handle(500);
        deliveryAssertContains('Family &lt;A&gt;', representativeFamilyResourcesGet($multiple['controller']));

        familyContextEndRepresentativeMembership($multiple['families'], 500, 33);
        $revalidated = representativeFamilyResourcesGet($multiple['controller']);
        deliveryAssertContains('Family B', $revalidated);
        assertSameValue(600, $multiple['session']->get('representative_family_context_id'));
    });

    $runner->add('Representative Family Resources binds every POST to the freshly resolved Family context', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        representativeFamilyResourcesGet($fixture['controller']);
        $fixture['select']->handle(600);
        $before = $fixture['families']->saveCalls();

        $response = representativeFamilyResourcesPost(
            $fixture['controller'],
            'updateAddress',
            representativeFamilyResourcesAddressPost(500, ['family_address_id' => '11']),
        );

        assertSameValue(403, http_response_code());
        deliveryAssertContains('Family resources unavailable', $response);
        assertSameValue($before, $fixture['families']->saveCalls());
        $familyB = (new GetFamilyResources($fixture['families']))->handle(600);
        assertSameValue('Address 11', familyResourcesFindById($familyB->addresses, 11)->label);

        $fixture['select']->handle(500);
        assertSameValue('', representativeFamilyResourcesPost(
            $fixture['controller'],
            'updateAddress',
            representativeFamilyResourcesAddressPost(500, [
                'family_address_id' => '11',
                'label' => 'Legitimate Family A Address',
            ]),
        ));
        assertSameValue(303, http_response_code());
    });

    $runner->add('Representative address authority is always self and other Representative history stays private', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        representativeFamilyResourcesPost($fixture['controller'], 'assignRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'representative_id' => '44',
            'family_address_id' => '11',
            'started_at' => '2026-08-11T09:00',
        ]);
        $resources = (new GetFamilyResources($fixture['families']))->handle(500);
        $self = array_values(array_filter(
            $resources->representativeAddressAssignments,
            static fn (object $item): bool => $item->isActive && $item->representativeId === 33,
        ));
        $other = array_values(array_filter(
            $resources->representativeAddressAssignments,
            static fn (object $item): bool => $item->isActive && $item->representativeId === 44,
        ));
        assertSameValue(1, count($self));
        assertSameValue(1, count($other));

        $page = representativeFamilyResourcesGet($fixture['controller']);
        assertSameValue(false, str_contains($page, 'name="representative_id"'));
        assertSameValue(false, str_contains($page, 'Representative 44'));
        assertSameValue(false, str_contains($page, 'value="85"'));

        $before = $fixture['families']->saveCalls();
        $denied = representativeFamilyResourcesPost($fixture['controller'], 'endRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'assignment_id' => '85',
            'ended_at' => '2026-08-11T10:00',
        ]);
        assertSameValue(422, http_response_code());
        deliveryAssertContains('Selected resource is not available', $denied);
        assertSameValue($before, $fixture['families']->saveCalls());

        representativeFamilyResourcesPost($fixture['controller'], 'assignRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'family_address_id' => '12',
            'started_at' => '2026-08-11T11:00',
        ]);
        $replaced = (new GetFamilyResources($fixture['families']))->handle(500);
        assertSameValue(1, count(array_filter(
            $replaced->representativeAddressAssignments,
            static fn (object $item): bool => $item->isActive && $item->representativeId === 33,
        )));
    });

    $runner->add('Representative cannot update or deactivate an Address actively used by another Representative', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        foreach ([
            ['updateAddress', representativeFamilyResourcesAddressPost(500, ['family_address_id' => '12'])],
            ['deactivateAddress', representativeFamilyResourcesIdentityPost('family_address_id', 12)],
        ] as [$method, $input]) {
            $before = $fixture['families']->saveCalls();
            $response = representativeFamilyResourcesPost($fixture['controller'], $method, $input);
            assertSameValue(422, http_response_code());
            deliveryAssertContains('This address cannot be changed from your account.', $response);
            assertSameValue(false, str_contains($response, 'Representative 44'));
            assertSameValue($before, $fixture['families']->saveCalls());
        }

        representativeFamilyResourcesPost(
            $fixture['controller'],
            'updateAddress',
            representativeFamilyResourcesAddressPost(500, [
                'family_address_id' => '11',
                'label' => 'Self permitted address',
            ]),
        );
        assertSameValue(303, http_response_code());

        representativeFamilyResourcesPost($fixture['controller'], 'assignRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'family_address_id' => '12',
            'started_at' => '2026-08-11T11:30',
        ]);
        $shared = representativeFamilyResourcesPost(
            $fixture['controller'],
            'updateAddress',
            representativeFamilyResourcesAddressPost(500, ['family_address_id' => '12']),
        );
        assertSameValue(422, http_response_code());
        deliveryAssertContains('This address cannot be changed from your account.', $shared);
    });

    $runner->add('Representative operates only active related Students using human labels', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        $page = representativeFamilyResourcesGet($fixture['controller']);
        deliveryAssertContains('&lt;script&gt;Student&lt;/script&gt; &amp; One', $page);
        assertSameValue(false, str_contains($page, 'Historical Student'));
        assertSameValue(false, str_contains($page, 'Other Family Student'));

        representativeFamilyResourcesPost($fixture['controller'], 'assignStudentAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'student_id' => '301',
            'family_address_id' => '11',
            'started_at' => '2026-08-11T12:00',
        ]);
        assertSameValue(303, http_response_code());
        $resources = (new GetFamilyResources($fixture['families']))->handle(500);
        $active = array_values(array_filter(
            $resources->studentAddressAssignments,
            static fn (object $item): bool => $item->isActive && $item->studentId === 301,
        ));
        assertSameValue(1, count($active));

        foreach ([302, 401] as $studentId) {
            $before = $fixture['families']->saveCalls();
            $denied = representativeFamilyResourcesPost($fixture['controller'], 'assignStudentAddress', [
                '_csrf_token' => 'delivery-csrf',
                'family_id' => '500',
                'student_id' => (string) $studentId,
                'family_address_id' => '11',
                'started_at' => '2026-08-11T13:00',
            ]);
            assertSameValue(422, http_response_code());
            deliveryAssertContains('Selected resource is not available', $denied);
            assertSameValue($before, $fixture['families']->saveCalls());
        }

        representativeFamilyResourcesPost($fixture['controller'], 'endStudentAddress', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'assignment_id' => (string) $active[0]->id,
            'ended_at' => '2026-08-11T14:00',
        ]);
        assertSameValue(303, http_response_code());
    });

    $runner->add('Representative manages Emergency Contacts and assignments only for active related Students', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createEmergencyContact',
            representativeFamilyResourcesEmergencyPost(),
        );
        representativeFamilyResourcesPost($fixture['controller'], 'updateEmergencyContact', [
            ...representativeFamilyResourcesEmergencyPost(),
            'family_emergency_contact_id' => '21',
            'names' => 'Updated contact',
        ]);
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'deactivateEmergencyContact',
            representativeFamilyResourcesIdentityPost('family_emergency_contact_id', 21),
        );
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'activateEmergencyContact',
            representativeFamilyResourcesIdentityPost('family_emergency_contact_id', 22),
        );
        representativeFamilyResourcesPost($fixture['controller'], 'assignEmergencyContact', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'family_emergency_contact_id' => '22',
            'student_id' => '301',
            'priority' => '2',
            'started_at' => '2026-08-11T15:00',
        ]);
        $resources = (new GetFamilyResources($fixture['families']))->handle(500);
        $assignment = array_values(array_filter(
            $resources->emergencyContactAssignments,
            static fn (object $item): bool => $item->isActive,
        ))[0];
        representativeFamilyResourcesPost($fixture['controller'], 'endEmergencyContact', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'assignment_id' => (string) $assignment->id,
            'ended_at' => '2026-08-11T16:00',
        ]);
        assertSameValue(303, http_response_code());

        $before = $fixture['families']->saveCalls();
        $invalidCatalog = representativeFamilyResourcesPost(
            $fixture['controller'],
            'createEmergencyContact',
            representativeFamilyResourcesEmergencyPost(['relationship_type_id' => '999']),
        );
        assertSameValue(422, http_response_code());
        deliveryAssertContains('active relationship type', $invalidCatalog);
        assertSameValue($before, $fixture['families']->saveCalls());
    });

    $runner->add('Representative manages Authorized Pickups with optional paired identification', function (): void {
        $fixture = representativeFamilyResourcesFixture();
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createAuthorizedPickup',
            representativeFamilyResourcesPickupPost([
                'document_type_id' => '',
                'document_number' => '',
            ]),
        );
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createAuthorizedPickup',
            representativeFamilyResourcesPickupPost(),
        );
        representativeFamilyResourcesPost($fixture['controller'], 'updateAuthorizedPickup', [
            ...representativeFamilyResourcesPickupPost(),
            'family_authorized_pickup_id' => '31',
            'names' => 'Updated pickup',
        ]);
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'deactivateAuthorizedPickup',
            representativeFamilyResourcesIdentityPost('family_authorized_pickup_id', 31),
        );
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'activateAuthorizedPickup',
            representativeFamilyResourcesIdentityPost('family_authorized_pickup_id', 32),
        );
        representativeFamilyResourcesPost($fixture['controller'], 'assignAuthorizedPickup', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'family_authorized_pickup_id' => '32',
            'student_id' => '301',
            'started_at' => '2026-08-11T17:00',
        ]);
        $resources = (new GetFamilyResources($fixture['families']))->handle(500);
        $assignment = array_values(array_filter(
            $resources->authorizedPickupAssignments,
            static fn (object $item): bool => $item->isActive,
        ))[0];
        representativeFamilyResourcesPost($fixture['controller'], 'endAuthorizedPickup', [
            '_csrf_token' => 'delivery-csrf',
            'family_id' => '500',
            'assignment_id' => (string) $assignment->id,
            'ended_at' => '2026-08-11T18:00',
        ]);
        assertSameValue(303, http_response_code());

        foreach ([
            ['document_type_id' => '999', 'document_number' => 'UNKNOWN'],
            ['document_type_id' => '9', 'document_number' => ''],
        ] as $override) {
            $before = $fixture['families']->saveCalls();
            $response = representativeFamilyResourcesPost(
                $fixture['controller'],
                'createAuthorizedPickup',
                representativeFamilyResourcesPickupPost($override),
            );
            assertSameValue(422, http_response_code());
            deliveryAssertContains('Review the submitted information', $response);
            assertSameValue($before, $fixture['families']->saveCalls());
        }
    });

    $runner->add('Representative Family Resources rejects CSRF across every resource group before mutation', function (): void {
        foreach ([
            ['createAddress', representativeFamilyResourcesAddressPost(500, ['_csrf_token' => 'invalid'])],
            ['assignRepresentativeAddress', [
                '_csrf_token' => 'invalid', 'family_id' => '500',
                'family_address_id' => '11', 'started_at' => '2026-08-11T09:00',
            ]],
            ['assignStudentAddress', [
                '_csrf_token' => 'invalid', 'family_id' => '500', 'student_id' => '301',
                'family_address_id' => '11', 'started_at' => '2026-08-11T09:00',
            ]],
            ['createEmergencyContact', representativeFamilyResourcesEmergencyPost(['_csrf_token' => 'invalid'])],
            ['createAuthorizedPickup', representativeFamilyResourcesPickupPost(['_csrf_token' => 'invalid'])],
        ] as [$method, $input]) {
            $fixture = representativeFamilyResourcesFixture();
            $before = $fixture['families']->saveCalls();
            assertSameValue('', representativeFamilyResourcesPost($fixture['controller'], $method, $input));
            assertSameValue(303, http_response_code());
            assertSameValue($before, $fixture['families']->saveCalls());
            assertSameValue(500, $fixture['session']->get('representative_family_context_id'));
            foreach (['person_id', 'representative_id', 'student_id', 'resource_id', 'assignment_id'] as $key) {
                assertSameValue(null, $fixture['session']->get($key));
            }
        }
    });

    $runner->add('Representative Family switch isolates resources and rejects stale forms with colliding child IDs', function (): void {
        $fixture = representativeFamilyResourcesFixture(withSecondFamily: true);
        $fixture['select']->handle(500);
        deliveryAssertContains('Family &lt;A&gt;', representativeFamilyResourcesGet($fixture['controller']));
        $fixture['select']->handle(600);
        $familyB = representativeFamilyResourcesGet($fixture['controller']);
        deliveryAssertContains('Family B', $familyB);
        assertSameValue(false, str_contains($familyB, 'Family &lt;A&gt;'));

        $before = $fixture['families']->saveCalls();
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'activateAddress',
            representativeFamilyResourcesIdentityPost('family_address_id', 11, 500),
        );
        assertSameValue(403, http_response_code());
        assertSameValue($before, $fixture['families']->saveCalls());

        $fixture['select']->handle(500);
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'updateAddress',
            representativeFamilyResourcesAddressPost(500, [
                'family_address_id' => '11',
                'label' => 'Family A after valid switch',
            ]),
        );
        assertSameValue(303, http_response_code());
    });

    $runner->add('Representative Family Resources preserves admin isolation privacy escaping and architecture', function (): void {
        $admin = representativeFamilyResourcesFixture(withRepresentative: false, withFamily: false);
        assertSameValue(403, representativeFamilyResourcesStatus($admin['controller']));

        $fixture = representativeFamilyResourcesFixture();
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createAddress',
            representativeFamilyResourcesAddressPost(500, [
                'label' => '<script>Address & "quoted"</script>',
                'reference' => '<script>reference</script>',
            ]),
        );
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createEmergencyContact',
            representativeFamilyResourcesEmergencyPost([
                'names' => '<script>Contact</script>',
                'observations' => 'A & B',
            ]),
        );
        representativeFamilyResourcesPost(
            $fixture['controller'],
            'createAuthorizedPickup',
            representativeFamilyResourcesPickupPost([
                'names' => '<script>Pickup</script>',
                'observations' => '"quoted" & safe',
            ]),
        );
        $page = representativeFamilyResourcesGet($fixture['controller']);
        foreach (['&lt;script&gt;Address', '&lt;script&gt;Contact', '&lt;script&gt;Pickup', '&amp;'] as $escaped) {
            deliveryAssertContains($escaped, $page);
        }
        assertSameValue(false, str_contains($page, '<script>'));
        assertSameValue(false, str_contains($page, 'Representative 44'));

        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/app/Family/Http/RepresentativeFamilyResourceController.php'
        );
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'FamilyRepository', 'PdoFamilyRepository',
            'TransactionRunner', 'FamilyAdministrationMiddleware', 'PersonAdministrationMiddleware'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        foreach (['ResolveFamilyContext', 'GetFamilyResources', 'GetFamilyMembership', 'GetStudent', 'GetPerson'] as $required) {
            deliveryAssertContains($required, $controller);
        }
        assertSameValue(1, substr_count($controller, '$this->resolveFamilyContext->handle()'));
        assertSameValue(false, str_contains($controller, 'catch (Throwable'));
        assertSameValue(false, str_contains($controller, 'catch (RuntimeException'));

        $view = (string) file_get_contents(
            dirname(__DIR__) . '/resources/views/representative-portal/resources.php'
        );
        foreach (['Enrollment', 'Submission', 'InstitutionalDocument', 'Billing', 'Medical', 'Transport',
            'leave-alone', 'Student Portal', 'ajax', 'province', 'canton', 'parish'] as $forbidden) {
            assertSameValue(false, stripos($view, $forbidden) !== false, $forbidden);
        }
        assertSameValue(0, preg_match('/antares|ueant|colegio/i', $view));
        assertSameValue(true, str_contains($view, 'htmlspecialchars'));

    });
}

/** @return array<string, mixed> */
function representativeFamilyResourcesFixture(
    bool $withUser = true,
    bool $withRepresentative = true,
    bool $withFamily = true,
    bool $withSecondFamily = false,
): array {
    $identity = familyContextAuthorizationFixture($withUser, $withRepresentative);
    $families = $identity['families'];
    if ($withFamily && $withRepresentative) {
        $families->seed(representativeFamilyResourcesFamily(500, 'Family <A>', 301));
    }
    if ($withSecondFamily && $withRepresentative) {
        $families->seed(representativeFamilyResourcesFamily(600, 'Family B', 401));
    }

    $today = representativeFamilyResourcesTime('2026-08-11');
    $persons = new InMemoryPersonApplicationRepository($today);
    $students = new InMemoryStudentApplicationRepository();
    foreach ([
        [301, 701, '<script>Student</script>', '& One'],
        [401, 801, 'Other Family', 'Student'],
    ] as [$studentId, $personId, $firstName, $surname]) {
        $persons->seed(representativeFamilyResourcesPerson($personId, $firstName, $surname, $today));
        $students->seed(representativeFamilyResourcesStudent($studentId, $personId, $today));
    }

    $options = new FamilyResourceFormOptions(
        [new FamilyResourceFormOption(201, 'PARENT', 'Parent')],
        [new FamilyResourceFormOption(9, 'ID', 'Identity document')],
    );
    $provider = new class($options) implements FamilyResourceFormOptionsProvider {
        public function __construct(private readonly FamilyResourceFormOptions $options)
        {
        }

        public function get(): FamilyResourceFormOptions
        {
            return $this->options;
        }
    };
    $relationships = new FakeRelationshipTypeLookup([201]);
    $documents = new FakeFamilyResourceDocumentTypeLookup([9]);
    $controller = new RepresentativeFamilyResourceController(
        $identity['resolve'],
        new GetFamilyResources($families),
        new GetFamilyMembership($families),
        new GetStudent($students),
        new GetPerson($persons),
        new CreateFamilyAddress($families),
        new UpdateFamilyAddress($families),
        new ActivateFamilyAddress($families),
        new DeactivateFamilyAddress($families),
        new AssignRepresentativeAddress($families),
        new EndRepresentativeAddressAssignment($families),
        new AssignStudentAddress($families),
        new EndStudentAddressAssignment($families),
        new CreateFamilyEmergencyContact($families, $relationships),
        new UpdateFamilyEmergencyContact($families, $relationships),
        new ActivateFamilyEmergencyContact($families),
        new DeactivateFamilyEmergencyContact($families),
        new AssignEmergencyContact($families),
        new EndEmergencyContactAssignment($families),
        new CreateFamilyAuthorizedPickup($families, $relationships, $documents),
        new UpdateFamilyAuthorizedPickup($families, $relationships, $documents),
        new ActivateFamilyAuthorizedPickup($families),
        new DeactivateFamilyAuthorizedPickup($families),
        new AssignAuthorizedPickup($families),
        new EndAuthorizedPickupAssignment($families),
        new FakeDeliveryCsrf(),
        $identity['session'],
        $provider,
    );

    return array_merge($identity, [
        'controller' => $controller,
        'portal' => new RepresentativePortalController(
            $identity['resolve'],
            $identity['select'],
            new FakeDeliveryCsrf(),
        ),
        'persons' => $persons,
        'students' => $students,
    ]);
}

function representativeFamilyResourcesFamily(int $familyId, string $displayName, int $activeStudentId): Family
{
    $offset = $familyId * 10;

    return Family::reconstitute(
        new FamilyId($familyId),
        new DisplayName($displayName),
        FamilyStatus::Active,
        [
            new FamilyRepresentative(
                new FamilyRepresentativeId($offset + 1),
                new RepresentativeId(33),
                new RelationshipTypeId(201),
                false,
                representativeFamilyResourcesTime('2026-01-01'),
                null,
            ),
            new FamilyRepresentative(
                new FamilyRepresentativeId($offset + 2),
                new RepresentativeId(44),
                new RelationshipTypeId(201),
                true,
                representativeFamilyResourcesTime('2026-01-01'),
                null,
            ),
        ],
        [
            new FamilyStudent(
                new FamilyStudentId($offset + 3),
                new StudentId($activeStudentId),
                representativeFamilyResourcesTime('2026-01-01'),
                null,
            ),
            new FamilyStudent(
                new FamilyStudentId($offset + 4),
                new StudentId(302),
                representativeFamilyResourcesTime('2025-01-01'),
                representativeFamilyResourcesTime('2025-12-31'),
            ),
        ],
        [
            representativeFamilyResourcesAddress(11, FamilyResourceStatus::Active),
            representativeFamilyResourcesAddress(12, FamilyResourceStatus::Active),
        ],
        [
            new RepresentativeAddressAssignment(
                new RepresentativeAddressAssignmentId(81),
                new FamilyAddressId(11),
                new RepresentativeId(33),
                representativeFamilyResourcesTime('2025-01-01'),
                representativeFamilyResourcesTime('2025-02-01'),
            ),
            new RepresentativeAddressAssignment(
                new RepresentativeAddressAssignmentId(85),
                new FamilyAddressId(12),
                new RepresentativeId(44),
                representativeFamilyResourcesTime('2026-01-01'),
                null,
            ),
        ],
        [new StudentAddressAssignment(
            new StudentAddressAssignmentId(82),
            new FamilyAddressId(11),
            new StudentId(302),
            representativeFamilyResourcesTime('2025-01-01'),
            representativeFamilyResourcesTime('2025-02-01'),
        )],
        [
            representativeFamilyResourcesContact(21, FamilyResourceStatus::Active),
            representativeFamilyResourcesContact(22, FamilyResourceStatus::Inactive),
        ],
        [new EmergencyContactAssignment(
            new EmergencyContactAssignmentId(83),
            new FamilyEmergencyContactId(21),
            new StudentId(302),
            new EmergencyContactPriority(1),
            representativeFamilyResourcesTime('2025-01-01'),
            representativeFamilyResourcesTime('2025-02-01'),
        )],
        [
            representativeFamilyResourcesPickup(31, FamilyResourceStatus::Active),
            representativeFamilyResourcesPickup(32, FamilyResourceStatus::Inactive),
        ],
        [new AuthorizedPickupAssignment(
            new AuthorizedPickupAssignmentId(84),
            new FamilyAuthorizedPickupId(31),
            new StudentId(302),
            representativeFamilyResourcesTime('2025-01-01'),
            representativeFamilyResourcesTime('2025-02-01'),
        )],
    );
}

function representativeFamilyResourcesAddress(int $id, FamilyResourceStatus $status): FamilyAddress
{
    return new FamilyAddress(
        new FamilyAddressId($id),
        new AddressLabel('Address ' . $id),
        new Address('Street ' . $id, 'N' . $id, null, null, 'Reference ' . $id, null),
        $status,
    );
}

function representativeFamilyResourcesContact(int $id, FamilyResourceStatus $status): FamilyEmergencyContact
{
    return new FamilyEmergencyContact(
        new FamilyEmergencyContactId($id),
        new FamilyResourceName('Contact ' . $id),
        new RelationshipTypeId(201),
        new EmergencyContactInformation('mobile ' . $id, null, null, 'Contact observation ' . $id),
        $status,
    );
}

function representativeFamilyResourcesPickup(int $id, FamilyResourceStatus $status): FamilyAuthorizedPickup
{
    return new FamilyAuthorizedPickup(
        new FamilyAuthorizedPickupId($id),
        new FamilyResourceName('Pickup ' . $id),
        new RelationshipTypeId(201),
        new AuthorizedPickupInformation('mobile ' . $id, null, 'Pickup observation ' . $id),
        null,
        $status,
    );
}

function representativeFamilyResourcesPerson(
    int $id,
    string $firstName,
    string $surname,
    DateTimeImmutable $today,
): Person {
    return new Person(
        new PersonIdentity($id),
        new PersonalName($firstName, null, $surname, null),
        null,
        representativeFamilyResourcesTime('2015-01-01'),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        $today,
    );
}

function representativeFamilyResourcesStudent(
    int $id,
    int $personId,
    DateTimeImmutable $today,
): Student {
    return new Student(
        new StudentIdentity($id),
        new StudentPersonId($personId),
        new InstitutionalCode('STUDENT-' . $id),
        new AdmissionDate(representativeFamilyResourcesTime('2025-01-01'), $today),
        StudentStatus::Active,
    );
}

function representativeFamilyResourcesGet(RepresentativeFamilyResourceController $controller): string
{
    deliveryRequest('GET', '/representative/resources');

    return $controller->index();
}

function representativeFamilyResourcesStatus(RepresentativeFamilyResourceController $controller): int
{
    representativeFamilyResourcesGet($controller);

    return http_response_code();
}

function representativeFamilyResourcesPost(
    RepresentativeFamilyResourceController $controller,
    string $method,
    array $input,
): string {
    deliveryRequest('POST', '/representative/resources/test', $input);

    return $controller->{$method}();
}

/** @param array<string, mixed> $overrides */
function representativeFamilyResourcesAddressPost(int $familyId = 500, array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'family_id' => (string) $familyId,
        'label' => 'Address label',
        'main_street' => 'Main street',
        'street_number' => 'N1',
        'secondary_street' => 'Cross street',
        'sector' => 'North',
        'reference' => 'Blue door',
        'latitude' => '-0.180653',
        'longitude' => '-78.467838',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function representativeFamilyResourcesEmergencyPost(array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'family_id' => '500',
        'names' => 'Emergency person',
        'relationship_type_id' => '201',
        'mobile_phone' => 'mobile extension',
        'phone' => 'phone extension',
        'email' => 'emergency@example.test',
        'observations' => 'Call first',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function representativeFamilyResourcesPickupPost(array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'family_id' => '500',
        'names' => 'Pickup person',
        'relationship_type_id' => '201',
        'mobile_phone' => 'mobile extension',
        'phone' => 'phone extension',
        'document_type_id' => '9',
        'document_number' => 'PICKUP-001',
        'observations' => 'Known to the Family',
    ], $overrides);
}

function representativeFamilyResourcesIdentityPost(string $field, int $id, int $familyId = 500): array
{
    return [
        '_csrf_token' => 'delivery-csrf',
        'family_id' => (string) $familyId,
        $field => (string) $id,
    ];
}

function representativeFamilyResourcesTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}
