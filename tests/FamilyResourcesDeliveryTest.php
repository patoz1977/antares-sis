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
use App\Family\Http\FamilyResourceController;
use App\Family\Http\FamilyResourceFormOption;
use App\Family\Http\FamilyResourceFormOptions;
use App\Family\Http\FamilyResourceFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoDocumentTypeLookup;
use App\Family\Infrastructure\Persistence\PdoFamilyResourceFormOptionsProvider;
use App\IdentityAccess\Application\Contract\SessionManager;
use PDOException;
use Tests\Support\TestRunner;

function registerFamilyResourcesDeliveryTests(TestRunner $runner): void
{
    $runner->add('Family Resources PDO catalogs are active ordered exact and fail visibly', function (): void {
        $manager = familySqliteManager();
        $pdo = $manager->connection();
        $pdo->exec('CREATE TABLE relationship_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)');
        $pdo->exec('CREATE TABLE document_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)');
        $pdo->exec("INSERT INTO relationship_types VALUES (2, 'OTHER', 'Zulu', 1), (1, 'PARENT', 'Alpha', 1), (3, 'OLD', 'Hidden', 0)");
        $pdo->exec("INSERT INTO document_types VALUES (12, 'PASS', 'Passport', 1), (11, 'ID', 'Identity', 1), (13, 'OLD', 'Hidden', 0)");

        $lookup = new PdoDocumentTypeLookup($manager);
        assertSameValue(true, $lookup->exists(11));
        assertSameValue(false, $lookup->exists(13));
        assertSameValue(false, $lookup->exists(999));
        assertSameValue(false, $lookup->exists(0));

        $options = (new PdoFamilyResourceFormOptionsProvider($manager))->get();
        assertSameValue(
            [[1, 'PARENT', 'Alpha'], [2, 'OTHER', 'Zulu']],
            array_map(static fn ($option): array => [$option->id, $option->code, $option->name], $options->relationshipTypes),
        );
        assertSameValue(
            [[11, 'ID', 'Identity'], [12, 'PASS', 'Passport']],
            array_map(static fn ($option): array => [$option->id, $option->code, $option->name], $options->documentTypes),
        );
        assertSameValue(true, $options->hasRelationshipType(1));
        assertSameValue(true, $options->hasDocumentType(12));
        assertSameValue(false, $options->hasDocumentType(13));

        $pdo->exec('UPDATE relationship_types SET is_active = 0');
        $pdo->exec('UPDATE document_types SET is_active = 0');
        $empty = (new PdoFamilyResourceFormOptionsProvider($manager))->get();
        assertSameValue([], $empty->relationshipTypes);
        assertSameValue([], $empty->documentTypes);

        $pdo->exec('DROP TABLE document_types');
        assertThrows(static fn (): bool => $lookup->exists(11), PDOException::class);
        assertThrows(
            static fn (): FamilyResourceFormOptions => (new PdoFamilyResourceFormOptionsProvider($manager))->get(),
            PDOException::class,
        );

        $lookupSource = (string) file_get_contents(dirname(__DIR__) . '/app/Family/Infrastructure/Persistence/PdoDocumentTypeLookup.php');
        deliveryAssertContains('prepare(', $lookupSource);
        deliveryAssertContains('is_active = TRUE', $lookupSource);
        assertSameValue(false, str_contains($lookupSource, 'catch'));
    });

    $runner->add('Family Resources exposes exact protected administrator routes only', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        $approved = [
            "get('/families/resources'",
            "post('/families/resources/addresses/create'",
            "post('/families/resources/addresses/update'",
            "post('/families/resources/addresses/activate'",
            "post('/families/resources/addresses/deactivate'",
            "post('/families/resources/representatives/address'",
            "post('/families/resources/representatives/address/end'",
            "post('/families/resources/students/address'",
            "post('/families/resources/students/address/end'",
            "post('/families/resources/emergency-contacts/create'",
            "post('/families/resources/emergency-contacts/update'",
            "post('/families/resources/emergency-contacts/activate'",
            "post('/families/resources/emergency-contacts/deactivate'",
            "post('/families/resources/emergency-contacts/assign'",
            "post('/families/resources/emergency-contacts/end'",
            "post('/families/resources/authorized-pickups/create'",
            "post('/families/resources/authorized-pickups/update'",
            "post('/families/resources/authorized-pickups/activate'",
            "post('/families/resources/authorized-pickups/deactivate'",
            "post('/families/resources/authorized-pickups/assign'",
            "post('/families/resources/authorized-pickups/end'",
        ];
        foreach ($approved as $route) {
            deliveryAssertContains($route, $routes);
        }
        assertSameValue(21, preg_match_all('/\$router->(?:get|post)\(\'\/families\/resources/', $routes));
        assertSameValue(20, preg_match_all('/\$router->post\(\'\/families\/resources/', $routes));
        assertSameValue(21, substr_count($routes, '[$familyResourceController,'));
        assertSameValue(false, str_contains($routes, '/representative/resources'));
        assertSameValue(false, str_contains($routes, '/families/resources/delete'));

        [$representativeGate] = familyAdministrationMiddleware('representative-22');
        $called = false;
        $response = $representativeGate->handle(new \Core\Http\Request(), function () use (&$called): \Core\Http\Response {
            $called = true;

            return new \Core\Http\Response();
        });
        assertSameValue('Forbidden', deliverySendResponse($response));
        assertSameValue(false, $called);
    });

    $runner->add('Family Resources GET establishes trusted context renders history and escapes output', function (): void {
        [$controller, $repository, $session] = familyResourcesDeliveryController();
        deliveryRequest('GET', '/families/resources?family_id=500', ['family_id' => '500']);
        $page = $controller->index();

        deliveryAssertContains('Resource Application Family', $page);
        foreach (['Addresses', 'Emergency Contacts', 'Authorized Pickups', 'History', 'Back to Family', 'Back to Families'] as $text) {
            deliveryAssertContains($text, $page);
        }
        assertSameValue(500, $session->get('_family_resources_trusted_family_id'));

        [$controller, $repository] = familyResourcesDeliveryController();
        familyResourcesOpen($controller);
        $storage = new \ReflectionProperty(InMemoryFamilyApplicationRepository::class, 'families');
        $storage->setValue($repository, []);
        deliveryRequest('POST', '/families/resources/addresses/create', familyResourcesAddressPost());
        $deleted = $controller->createAddress();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Family not found', $deleted);
        assertSameValue(0, $repository->saveCalls());
        assertSameValue(false, str_contains($page, 'password'));
        assertSameValue(false, str_contains($page, 'Enrollment'));

        deliveryRequest('GET', '/families/resources?family_id=999999', ['family_id' => '999999']);
        $missing = $controller->index();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Family not found', $missing);
        assertSameValue(false, str_contains($missing, 'SQLSTATE'));

        $invalidRepository = familyResourcesApplicationRepository();
        $wrong = familyResourcesApplicationAggregate();
        $wrongIdentity = \App\Family\Domain\Family::reconstitute(
            new \App\Family\Domain\ValueObject\FamilyId(501),
            $wrong->displayName(),
            $wrong->status(),
            $wrong->representatives(),
            $wrong->students(),
            $wrong->addresses(),
            $wrong->representativeAddressAssignments(),
            $wrong->studentAddressAssignments(),
            $wrong->emergencyContacts(),
            $wrong->emergencyContactAssignments(),
            $wrong->authorizedPickups(),
            $wrong->authorizedPickupAssignments(),
        );
        $invalidStorage = new \ReflectionProperty(InMemoryFamilyApplicationRepository::class, 'families');
        $invalidStorage->setValue($invalidRepository, [500 => $wrongIdentity]);
        [$invalidController] = familyResourcesDeliveryController($invalidRepository);
        deliveryRequest('GET', '/families/resources?family_id=500', ['family_id' => '500']);
        $invalid = $invalidController->index();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('operation could not be confirmed', $invalid);

        $escapedRepository = new InMemoryFamilyApplicationRepository();
        $aggregate = familyResourcesApplicationAggregate();
        $escapedRepository->seed(\App\Family\Domain\Family::reconstitute(
            $aggregate->id(),
            new \App\Family\Domain\ValueObject\DisplayName('<script>alert(1)</script>'),
            $aggregate->status(),
            $aggregate->representatives(),
            $aggregate->students(),
            $aggregate->addresses(),
            $aggregate->representativeAddressAssignments(),
            $aggregate->studentAddressAssignments(),
            $aggregate->emergencyContacts(),
            $aggregate->emergencyContactAssignments(),
            $aggregate->authorizedPickups(),
            $aggregate->authorizedPickupAssignments(),
        ));
        [$escapedController] = familyResourcesDeliveryController($escapedRepository);
        deliveryRequest('GET', '/families/resources?family_id=500', ['family_id' => '500']);
        $escapedPage = $escapedController->index();
        deliveryAssertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $escapedPage);
        assertSameValue(false, str_contains($escapedPage, '<script>alert(1)</script>'));
    });

    $runner->add('Family Resources trusted context rejects tampering expiry and CSRF before save', function (): void {
        [$controller, $repository] = familyResourcesDeliveryController();
        deliveryRequest('POST', '/families/resources/addresses/create', familyResourcesAddressPost(500));
        $expired = $controller->createAddress();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('selection expired', $expired);
        assertSameValue(0, $repository->saveCalls());

        [$controller, $repository, $session] = familyResourcesDeliveryController();
        familyResourcesOpen($controller);
        deliveryRequest('POST', '/families/resources/addresses/create', familyResourcesAddressPost(999));
        $tampered = $controller->createAddress();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('identity cannot be changed', $tampered);
        assertSameValue(0, $repository->saveCalls());
        assertSameValue(500, $session->get('_family_resources_trusted_family_id'));

        foreach ([
            ['createAddress', familyResourcesAddressPost(500, ['_csrf_token' => 'invalid'])],
            ['createEmergencyContact', familyResourcesEmergencyPost(500, ['_csrf_token' => 'invalid'])],
            ['createAuthorizedPickup', familyResourcesPickupPost(500, ['_csrf_token' => 'invalid'])],
            ['assignAuthorizedPickup', familyResourcesPickupAssignmentPost(500, ['_csrf_token' => 'invalid'])],
        ] as [$method, $input]) {
            [$controller, $repository] = familyResourcesDeliveryController();
            familyResourcesOpen($controller);
            deliveryRequest('POST', '/families/resources/test', $input);
            assertSameValue('', $controller->{$method}());
            assertSameValue(303, http_response_code());
            assertSameValue(0, $repository->saveCalls());
        }
    });

    $runner->add('Family Resources administrator completes all address commands with explicit history', function (): void {
        [$controller, $repository] = familyResourcesDeliveryController();
        familyResourcesPost($controller, 'createAddress', familyResourcesAddressPost());
        familyResourcesPost($controller, 'updateAddress', familyResourcesAddressPost(500, [
            'family_address_id' => '11', 'label' => 'Updated home',
        ]));
        familyResourcesPost($controller, 'deactivateAddress', familyResourcesIdentityPost('family_address_id', 11));
        familyResourcesPost($controller, 'activateAddress', familyResourcesIdentityPost('family_address_id', 12));
        familyResourcesPost($controller, 'assignRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500', 'representative_id' => '101',
            'family_address_id' => '12', 'started_at' => '2026-08-11T09:10',
        ]);
        $output = (new GetFamilyResources($repository))->handle(500);
        $representativeAssignment = array_values(array_filter(
            $output->representativeAddressAssignments,
            static fn ($item): bool => $item->isActive,
        ))[0];
        familyResourcesPost($controller, 'endRepresentativeAddress', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500',
            'assignment_id' => (string) $representativeAssignment->id, 'ended_at' => '2026-08-11T10:10',
        ]);
        familyResourcesPost($controller, 'assignStudentAddress', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500', 'student_id' => '301',
            'family_address_id' => '12', 'started_at' => '2026-08-11T11:10',
        ]);
        $output = (new GetFamilyResources($repository))->handle(500);
        $studentAssignment = array_values(array_filter(
            $output->studentAddressAssignments,
            static fn ($item): bool => $item->isActive,
        ))[0];
        familyResourcesPost($controller, 'endStudentAddress', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500',
            'assignment_id' => (string) $studentAssignment->id, 'ended_at' => '2026-08-11T12:10',
        ]);

        deliveryRequest('GET', '/families/resources?family_id=500', ['family_id' => '500']);
        $page = $controller->index();
        deliveryAssertContains('Updated home', $page);
        deliveryAssertContains('2026-08-11T12:10:00+00:00', $page);
    });

    $runner->add('Family Resources administrator completes Emergency Contact lifecycle and assignment', function (): void {
        [$controller, $repository] = familyResourcesDeliveryController();
        familyResourcesPost($controller, 'createEmergencyContact', familyResourcesEmergencyPost());
        familyResourcesPost($controller, 'updateEmergencyContact', familyResourcesEmergencyPost(500, [
            'family_emergency_contact_id' => '21', 'names' => 'Updated contact',
        ]));
        familyResourcesPost($controller, 'deactivateEmergencyContact', familyResourcesIdentityPost('family_emergency_contact_id', 21));
        familyResourcesPost($controller, 'activateEmergencyContact', familyResourcesIdentityPost('family_emergency_contact_id', 22));
        familyResourcesPost($controller, 'assignEmergencyContact', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500',
            'family_emergency_contact_id' => '22', 'student_id' => '301', 'priority' => '2',
            'started_at' => '2026-08-11T13:10',
        ]);
        $output = (new GetFamilyResources($repository))->handle(500);
        $assignment = array_values(array_filter(
            $output->emergencyContactAssignments,
            static fn ($item): bool => $item->isActive,
        ))[0];
        familyResourcesPost($controller, 'endEmergencyContact', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500',
            'assignment_id' => (string) $assignment->id, 'ended_at' => '2026-08-11T14:10',
        ]);
        $output = (new GetFamilyResources($repository))->handle(500);
        assertSameValue(false, familyResourcesFindById($output->emergencyContactAssignments, $assignment->id)->isActive);
    });

    $runner->add('Family Resources administrator completes pickup lifecycle without requiring identification', function (): void {
        [$controller, $repository] = familyResourcesDeliveryController();
        familyResourcesPost($controller, 'createAuthorizedPickup', familyResourcesPickupPost(500, [
            'document_type_id' => '', 'document_number' => '',
        ]));
        familyResourcesPost($controller, 'updateAuthorizedPickup', familyResourcesPickupPost(500, [
            'family_authorized_pickup_id' => '31', 'names' => 'Updated pickup',
        ]));
        familyResourcesPost($controller, 'deactivateAuthorizedPickup', familyResourcesIdentityPost('family_authorized_pickup_id', 31));
        familyResourcesPost($controller, 'activateAuthorizedPickup', familyResourcesIdentityPost('family_authorized_pickup_id', 32));
        familyResourcesPost($controller, 'assignAuthorizedPickup', familyResourcesPickupAssignmentPost(500, [
            'family_authorized_pickup_id' => '32',
        ]));
        $output = (new GetFamilyResources($repository))->handle(500);
        $assignment = array_values(array_filter(
            $output->authorizedPickupAssignments,
            static fn ($item): bool => $item->isActive,
        ))[0];
        familyResourcesPost($controller, 'endAuthorizedPickup', [
            '_csrf_token' => 'delivery-csrf', 'family_id' => '500',
            'assignment_id' => (string) $assignment->id, 'ended_at' => '2026-08-11T16:10',
        ]);
        $output = (new GetFamilyResources($repository))->handle(500);
        assertSameValue(false, familyResourcesFindById($output->authorizedPickupAssignments, $assignment->id)->isActive);
    });

    $runner->add('Family Resources validates HTTP catalogs fields and empty option behavior', function (): void {
        foreach ([
            ['createAddress', familyResourcesAddressPost(500, ['latitude' => '-0.1', 'longitude' => ''])],
            ['createAddress', familyResourcesAddressPost(500, ['main_street' => ['not scalar']])],
            ['createEmergencyContact', familyResourcesEmergencyPost(500, ['observations' => ['not scalar']])],
            ['createEmergencyContact', familyResourcesEmergencyPost(500, ['email' => 'invalid'])],
            ['createEmergencyContact', familyResourcesEmergencyPost(500, ['relationship_type_id' => '999'])],
            ['createAuthorizedPickup', familyResourcesPickupPost(500, ['document_type_id' => '9', 'document_number' => ''])],
            ['activateAddress', familyResourcesIdentityPost('family_address_id', 999)],
            ['assignAuthorizedPickup', familyResourcesPickupAssignmentPost(500, ['started_at' => '2026-08-11 15:10'])],
        ] as [$method, $input]) {
            [$controller, $repository] = familyResourcesDeliveryController();
            familyResourcesOpen($controller);
            deliveryRequest('POST', '/families/resources/test', $input);
            $response = $controller->{$method}();
            assertSameValue(422, http_response_code());
            deliveryAssertContains('Review the submitted information', $response);
            if ($method === 'createAuthorizedPickup') {
                deliveryAssertContains('Pickup person', $response);
            }
            assertSameValue(0, $repository->saveCalls());
        }

        [$controller] = familyResourcesDeliveryController(options: new FamilyResourceFormOptions([], []));
        familyResourcesOpen($controller);
        deliveryRequest('GET', '/families/resources?family_id=500', ['family_id' => '500']);
        $page = $controller->index();
        deliveryAssertContains('relationship types are unavailable', $page);
        deliveryAssertContains('may still be saved without identification', $page);
        assertSameValue(false, str_contains($page, 'name="document_type_id" required'));
    });

    $runner->add('Family Resources delivery preserves architecture and forbidden scope', function (): void {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Family/Http/FamilyResourceController.php');
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'FamilyRepository', 'Enrollment', 'Submission'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        $view = (string) file_get_contents(dirname(__DIR__) . '/resources/views/families/resources.php');
        foreach (['province', 'canton', 'parish', 'country', 'postal', 'username', 'password', 'ajax'] as $forbidden) {
            assertSameValue(false, stripos($view, $forbidden) !== false, $forbidden);
        }
        assertSameValue(false, str_contains($controller, 'catch (\\Throwable'));
        assertSameValue(false, str_contains($controller, 'catch (\\RuntimeException'));
        deliveryAssertContains('_family_resources_trusted_family_id', $controller);
        foreach (['RepresentativeId', 'StudentId', 'AddressId', 'EmergencyContactId', 'PickupId', 'AssignmentId'] as $forbiddenSession) {
            assertSameValue(false, str_contains($controller, "put(self::TRUSTED_FAMILY_ID_KEY, \$$forbiddenSession"));
        }
    });
}

/** @return array{FamilyResourceController, InMemoryFamilyApplicationRepository, FakeSessionManager} */
function familyResourcesDeliveryController(
    ?InMemoryFamilyApplicationRepository $repository = null,
    ?FamilyResourceFormOptions $options = null,
): array {
    $repository ??= familyResourcesApplicationRepository();
    $options ??= new FamilyResourceFormOptions(
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
    $session = new FakeSessionManager();

    return [
        new FamilyResourceController(
            new GetFamilyResources($repository),
            new GetFamilyMembership($repository),
            new CreateFamilyAddress($repository),
            new UpdateFamilyAddress($repository),
            new ActivateFamilyAddress($repository),
            new DeactivateFamilyAddress($repository),
            new AssignRepresentativeAddress($repository),
            new EndRepresentativeAddressAssignment($repository),
            new AssignStudentAddress($repository),
            new EndStudentAddressAssignment($repository),
            new CreateFamilyEmergencyContact($repository, $relationships),
            new UpdateFamilyEmergencyContact($repository, $relationships),
            new ActivateFamilyEmergencyContact($repository),
            new DeactivateFamilyEmergencyContact($repository),
            new AssignEmergencyContact($repository),
            new EndEmergencyContactAssignment($repository),
            new CreateFamilyAuthorizedPickup($repository, $relationships, $documents),
            new UpdateFamilyAuthorizedPickup($repository, $relationships, $documents),
            new ActivateFamilyAuthorizedPickup($repository),
            new DeactivateFamilyAuthorizedPickup($repository),
            new AssignAuthorizedPickup($repository),
            new EndAuthorizedPickupAssignment($repository),
            new FakeDeliveryCsrf(),
            $session,
            $provider,
        ),
        $repository,
        $session,
    ];
}

function familyResourcesOpen(FamilyResourceController $controller, int $familyId = 500): string
{
    deliveryRequest('GET', '/families/resources?family_id=' . $familyId, ['family_id' => (string) $familyId]);

    return $controller->index();
}

function familyResourcesPost(FamilyResourceController $controller, string $method, array $input): void
{
    familyResourcesOpen($controller);
    deliveryRequest('POST', '/families/resources/test', $input);
    assertSameValue('', $controller->{$method}());
    assertSameValue(303, http_response_code());
}

/** @param array<string, mixed> $overrides */
function familyResourcesAddressPost(int $familyId = 500, array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf', 'family_id' => (string) $familyId,
        'label' => 'Home', 'main_street' => 'Main street', 'street_number' => 'N1',
        'secondary_street' => 'Cross street', 'sector' => 'North', 'reference' => 'Blue door',
        'latitude' => '-0.180653', 'longitude' => '-78.467838',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function familyResourcesEmergencyPost(int $familyId = 500, array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf', 'family_id' => (string) $familyId,
        'names' => 'Emergency person', 'relationship_type_id' => '201',
        'mobile_phone' => 'mobile extension', 'phone' => 'phone extension',
        'email' => 'emergency@example.test', 'observations' => 'Call first',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function familyResourcesPickupPost(int $familyId = 500, array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf', 'family_id' => (string) $familyId,
        'names' => 'Pickup person', 'relationship_type_id' => '201',
        'mobile_phone' => 'mobile extension', 'phone' => 'phone extension',
        'document_type_id' => '9', 'document_number' => 'PICKUP-001',
        'observations' => 'Known to the Family',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function familyResourcesPickupAssignmentPost(int $familyId = 500, array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf', 'family_id' => (string) $familyId,
        'family_authorized_pickup_id' => '31', 'student_id' => '301',
        'started_at' => '2026-08-11T15:10',
    ], $overrides);
}

function familyResourcesIdentityPost(string $field, int $id): array
{
    return [
        '_csrf_token' => 'delivery-csrf',
        'family_id' => '500',
        $field => (string) $id,
    ];
}
