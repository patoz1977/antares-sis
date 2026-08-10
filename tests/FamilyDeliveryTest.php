<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\GetFamily;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Http\FamilyAdministrationMiddleware;
use App\Family\Http\FamilyController;
use App\Family\Http\FamilyFormOption;
use App\Family\Http\FamilyFormOptions;
use App\Family\Http\FamilyFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoFamilyFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoRelationshipTypeLookup;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\Person\Http\PersonFormOptions;
use App\Representative\Domain\RepresentativeStatus;
use App\Student\Domain\StudentStatus;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthenticationMiddleware;
use Core\Security\AuthenticatedUserProviderInterface;
use PDO;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerFamilyDeliveryTests(TestRunner $runner): void
{
    $runner->add('Family delivery enforces authentication then exact admin authorization', function (): void {
        $nextCalled = false;
        $authentication = new AuthenticationMiddleware(
            new class implements AuthenticatedUserProviderInterface {
                public function check(): bool
                {
                    return false;
                }
            },
        );
        $response = $authentication->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response();
        });
        deliverySendResponse($response);
        assertSameValue(302, http_response_code());
        assertSameValue(false, $nextCalled);

        [$operator] = familyAdministrationMiddleware('operator');
        $response = $operator->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response();
        });
        assertSameValue('Forbidden', deliverySendResponse($response));
        assertSameValue(403, http_response_code());

        [$admin] = familyAdministrationMiddleware('admin');
        $response = $admin->handle(new Request(), static fn (Request $request): Response =>
            (new Response())->content('allowed'));
        assertSameValue('allowed', deliverySendResponse($response));
        assertSameValue(200, http_response_code());
    });

    $runner->add('Family navigation exposes only the six approved routes in middleware order', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        foreach ([
            "get('/families'",
            "get('/families/create'",
            "post('/families/create'",
            "get('/families/show'",
            "get('/families/students/create'",
            "post('/families/students/create'",
        ] as $route) {
            assertSameValue(true, str_contains($routes, $route));
        }
        assertSameValue(6, substr_count($routes, '], $familyMiddleware);'));
        assertSameValue(false, str_contains($routes, '/families/delete'));
        assertSameValue(false, method_exists(FamilyController::class, 'delete'));

        $authenticationPosition = strpos($routes, 'AuthenticationMiddleware::class', strpos($routes, '$familyMiddleware'));
        $administrationPosition = strpos($routes, 'FamilyAdministrationMiddleware::class', strpos($routes, '$familyMiddleware'));
        assertSameValue(true, is_int($authenticationPosition) && is_int($administrationPosition));
        assertSameValue(true, $authenticationPosition < $administrationPosition);

        $adminDashboard = deliveryDashboardController('admin')->dashboard();
        $operatorDashboard = deliveryDashboardController('operator')->dashboard();
        deliveryAssertContains('href="/persons"', $adminDashboard);
        deliveryAssertContains('href="/families"', $adminDashboard);
        assertSameValue(false, str_contains($operatorDashboard, 'href="/families"'));
    });

    $runner->add('Family index forms and safe detail navigation are available', function (): void {
        [$controller, $environment] = familyDeliveryController();
        $index = $controller->index();
        deliveryAssertContains('Create Representative and Family', $index);
        deliveryAssertContains('action="/families/show"', $index);

        deliveryRequest('GET', '/families/show?id=' . $environment->familyId, ['id' => (string) $environment->familyId]);
        $detail = $controller->show();
        deliveryAssertContains('Existing Composite Family', $detail);
        deliveryAssertContains('Active primary Representative', $detail);
        deliveryAssertContains('History', $detail);
        deliveryAssertContains('Add Student', $detail);

        deliveryRequest('GET', '/families/show?id=999999', ['id' => '999999']);
        $missing = $controller->show();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Family not found', $missing);
        assertSameValue(false, str_contains($missing, 'SQLSTATE'));

        deliveryRequest('GET', '/families/show?id=bad', ['id' => 'bad']);
        assertSameValue('', $controller->show());
        assertSameValue(302, http_response_code());
        deliveryAssertContains('valid positive Family ID', $controller->index());
    });

    $runner->add('Representative and Family form is composite catalog-backed and escaped', function (): void {
        $options = new FamilyFormOptions(
            [new FamilyFormOption(11, 'PARENT', '<Parent>')],
            [FamilyStatus::Active, FamilyStatus::Inactive],
        );
        [$controller] = familyDeliveryController(familyOptions: $options);
        $form = $controller->showCreateRepresentativeFamily();

        foreach (['Person', 'Representative', 'Family', 'name="_csrf_token"', 'name="started_at"'] as $text) {
            deliveryAssertContains($text, $form);
        }
        deliveryAssertContains('&lt;Parent&gt;', $form);
        assertSameValue(false, str_contains($form, '<Parent>'));
        foreach (['username', 'password', 'Enrollment', 'Student'] as $forbidden) {
            assertSameValue(false, str_contains($form, $forbidden));
        }
    });

    $runner->add('valid Representative and Family POST commits the full flow and redirects', function (): void {
        [$controller, $environment] = familyDeliveryController();
        deliveryRequest('POST', '/families/create', familyRepresentativeDeliveryInput());

        assertSameValue('', $controller->createRepresentativeFamily());
        assertSameValue(303, http_response_code());
        assertCompositeTransactionCommitted($environment->transactions);
        assertSameValue(1, $environment->persons->saveCalls());
        assertSameValue(1, $environment->representatives->saveCalls());
        assertSameValue(1, $environment->families->saveCalls());

        $created = $environment->families->findActiveByRepresentativeId(
            new \App\Family\Domain\ValueObject\RepresentativeId(501),
        );
        assertSameValue(1, count($created));
        $primary = $created[0]->primaryRepresentative();
        assertSameValue(true, $primary->isPrimary() && $primary->isActive());
        deliveryAssertContains(
            'Family and primary Representative created successfully.',
            $controller->index(),
        );
    });

    $runner->add('Representative delivery rejects CSRF and HTTP validation before orchestration', function (): void {
        foreach ([
            'invalid CSRF' => ['_csrf_token' => 'invalid'],
            'non scalar' => ['first_name' => ['invalid']],
            'required' => ['display_name' => ''],
            'date' => ['birth_date' => '2026-02-30'],
            'timestamp' => ['started_at' => '2026-08-09 10:00'],
            'catalog' => ['sex_id' => '999'],
            'email' => ['work_email' => 'not-an-email'],
            'document pair' => ['document_number' => ''],
        ] as $label => $changes) {
            [$controller, $environment] = familyDeliveryController();
            deliveryRequest('POST', '/families/create', familyRepresentativeDeliveryInput($changes));
            $response = $controller->createRepresentativeFamily();

            if ($label === 'invalid CSRF') {
                assertSameValue('', $response);
                assertSameValue(303, http_response_code());
                $response = $controller->showCreateRepresentativeFamily();
            } else {
                assertSameValue(422, http_response_code());
            }
            if ($label === 'non scalar') {
                deliveryAssertContains('must be a single value', $response);
            } else {
                deliveryAssertContains('Ada', $response);
            }
            assertSameValue(0, $environment->persons->saveCalls());
        }
    });

    $runner->add('Representative delivery maps known conflicts and preserves atomic rollback', function (): void {
        $environment = new CompositeOrchestrationEnvironment();
        $environment->persons->seed(deliveryPerson(77, 'DELIVERY-REP-001'));
        [$controller] = familyDeliveryController(environment: $environment);
        deliveryRequest('POST', '/families/create', familyRepresentativeDeliveryInput());
        $response = $controller->createRepresentativeFamily();
        assertComposite(
            http_response_code() === 422,
            'duplicate identification returned ' . http_response_code(),
        );
        deliveryAssertContains('already uses that identification', $response);
        assertSameValue(0, $environment->representatives->saveCalls());
        assertSameValue(0, $environment->families->saveCalls());

        $environment = new CompositeOrchestrationEnvironment();
        $before = compositeRepositoryState($environment);
        $options = new FamilyFormOptions(
            [new FamilyFormOption(99, 'UNAVAILABLE', 'Unavailable after validation')],
            [FamilyStatus::Active, FamilyStatus::Inactive],
        );
        [$controller] = familyDeliveryController($environment, familyOptions: $options);
        deliveryRequest('POST', '/families/create', familyRepresentativeDeliveryInput([
            'relationship_type_id' => '99',
        ]));
        $response = $controller->createRepresentativeFamily();
        assertComposite(
            http_response_code() === 422,
            'RelationshipType race returned ' . http_response_code(),
        );
        deliveryAssertContains('active relationship type', $response);
        assertCompositeRollback($environment, $before, 'delivery relationship race');
    });

    $runner->add('empty Family or Person catalogs disable composite submission', function (): void {
        [$controller] = familyDeliveryController(familyOptions: new FamilyFormOptions(
            [],
            [FamilyStatus::Active, FamilyStatus::Inactive],
        ));
        $form = $controller->showCreateRepresentativeFamily();
        deliveryAssertContains('relationship types are unavailable', $form);
        deliveryAssertContains('type="submit" disabled', $form);

        [$controller] = familyDeliveryController(personOptions: deliveryEmptyOptions());
        $form = $controller->showCreateRepresentativeFamily();
        deliveryAssertContains('required form catalogs are unavailable', $form);
        deliveryAssertContains('type="submit" disabled', $form);
    });

    $runner->add('Add Student binds Family identity to session and commits the approved flow', function (): void {
        [$controller, $environment] = familyDeliveryController();
        deliveryRequest('GET', '/families/students/create?family_id=' . $environment->familyId, [
            'family_id' => (string) $environment->familyId,
        ]);
        $form = $controller->showCreateStudent();
        deliveryAssertContains('Existing Composite Family', $form);
        deliveryAssertContains('name="family_id" value="401"', $form);

        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput($environment->familyId));
        assertSameValue('', $controller->createStudent());
        assertSameValue(303, http_response_code());
        assertCompositeTransactionCommitted($environment->transactions);
        assertSameValue(1, $environment->persons->saveCalls());
        assertSameValue(1, $environment->students->saveCalls());
        $family = $environment->families->findById(
            new \App\Family\Domain\ValueObject\FamilyId($environment->familyId),
        );
        assertSameValue('Existing Composite Family', $family?->displayName()->value());
        assertSameValue(1, count($family?->activeStudents() ?? []));
    });

    $runner->add('Add Student rejects tampering expired session CSRF and invalid navigation', function (): void {
        [$controller, $environment] = familyDeliveryController();
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput($environment->familyId));
        assertSameValue('', $controller->createStudent());
        assertSameValue(303, http_response_code());
        assertSameValue(0, $environment->persons->saveCalls());
        deliveryAssertContains('selection expired', $controller->index());

        [$controller, $environment] = familyDeliveryController();
        familyOpenStudentForm($controller, $environment->familyId);
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput(999));
        $response = $controller->createStudent();
        assertComposite(
            http_response_code() === 422,
            'Family identity tampering returned ' . http_response_code(),
        );
        deliveryAssertContains('identity cannot be changed', $response);
        assertSameValue(0, $environment->persons->saveCalls());

        familyOpenStudentForm($controller, $environment->familyId);
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput(
            $environment->familyId,
            ['_csrf_token' => 'invalid'],
        ));
        assertSameValue('', $controller->createStudent());
        assertSameValue(303, http_response_code());
        assertSameValue(0, $environment->persons->saveCalls());

        deliveryRequest('GET', '/families/students/create?family_id=999999', ['family_id' => '999999']);
        $missing = $controller->showCreateStudent();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Family not found', $missing);
    });

    $runner->add('Add Student maps functional failures and rolls back every persisted stage', function (): void {
        $environment = new CompositeOrchestrationEnvironment();
        $environment->students->seed(compositeStudentFixture(79, 80, 'DELIVERY-STUDENT-001'));
        [$controller] = familyDeliveryController($environment);
        familyOpenStudentForm($controller, $environment->familyId);
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput($environment->familyId));
        $response = $controller->createStudent();
        assertComposite(
            http_response_code() === 422,
            'duplicate institutional code returned ' . http_response_code(),
        );
        deliveryAssertContains('institutional code is already in use', $response);
        assertSameValue(0, $environment->persons->saveCalls());

        $environment = new CompositeOrchestrationEnvironment();
        [$controller] = familyDeliveryController($environment);
        familyOpenStudentForm($controller, $environment->familyId);
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput(
            $environment->familyId,
            ['admission_date' => '2999-08-10'],
        ));
        $response = $controller->createStudent();
        assertComposite(
            http_response_code() === 422,
            'future admission date returned ' . http_response_code(),
        );
        deliveryAssertContains('Review the entered Student data', $response);
        assertSameValue(0, $environment->persons->saveCalls());

        $environment = new CompositeOrchestrationEnvironment();
        $repository = new AlwaysActiveStudentFamilyRepository($environment->families, $environment->familyId);
        [$controller] = familyDeliveryController($environment, studentFamilies: $repository);
        familyOpenStudentForm($controller, $environment->familyId);
        $before = compositeRepositoryState($environment);
        deliveryRequest('POST', '/families/students/create', familyStudentDeliveryInput($environment->familyId));
        $response = $controller->createStudent();
        assertComposite(
            http_response_code() === 422,
            'active Family conflict returned ' . http_response_code(),
        );
        deliveryAssertContains('already has an active Family', $response);
        assertCompositeRollback($environment, $before, 'delivery active Family conflict');
    });

    $runner->add('Family PDO delivery adapters use active catalogs with exact statuses', function (): void {
        $manager = familySqliteManager();
        $pdo = $manager->connection();
        $pdo->exec('CREATE TABLE relationship_types (id INTEGER PRIMARY KEY, code TEXT, name TEXT, is_active INTEGER)');
        $pdo->exec("INSERT INTO relationship_types VALUES (2, 'OTHER', 'Zulu & <Other>', 1)");
        $pdo->exec("INSERT INTO relationship_types VALUES (1, 'PARENT', 'Alpha Parent', 1)");
        $pdo->exec("INSERT INTO relationship_types VALUES (3, 'INACTIVE', 'Hidden', 0)");

        $lookup = new PdoRelationshipTypeLookup($manager);
        assertSameValue(true, $lookup->exists(1));
        assertSameValue(false, $lookup->exists(3));
        assertSameValue(false, $lookup->exists(999));
        assertSameValue(false, $lookup->exists(0));

        $options = (new PdoFamilyFormOptionsProvider($manager))->get();
        assertSameValue([1, 2], array_map(static fn (FamilyFormOption $option): int => $option->id, $options->relationshipTypes));
        assertSameValue('Zulu & <Other>', $options->relationshipTypes[1]->name);
        assertSameValue([FamilyStatus::Active, FamilyStatus::Inactive], $options->statuses);
        assertSameValue(true, $options->isReadyForSave());

        $pdo->exec('UPDATE relationship_types SET is_active = 0');
        assertSameValue(false, (new PdoFamilyFormOptionsProvider($manager))->get()->isReadyForSave());

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/Family/Infrastructure/Persistence/PdoRelationshipTypeLookup.php',
        );
        assertSameValue(true, str_contains($source, 'prepare('));
        assertSameValue(true, str_contains($source, 'is_active = TRUE'));
        assertSameValue(false, preg_match('/relationshipTypeId\s*===?\s*\d+/', $source) === 1);
    });

    $runner->add('Family delivery keeps orchestration boundaries security and White Label scope', function (): void {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Family/Http/FamilyController.php');
        $reflection = new ReflectionClass(FamilyController::class);
        $dependencies = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $reflection->getConstructor()?->getParameters() ?? [],
        );
        assertSameValue([
            \App\Family\Application\Orchestration\CreateRepresentativeFamily::class,
            \App\Family\Application\Orchestration\CreateStudentInFamily::class,
            GetFamily::class,
            \App\IdentityAccess\Application\Contract\CsrfTokenManager::class,
            \App\IdentityAccess\Application\Contract\SessionManager::class,
            \App\Person\Http\PersonFormOptionsProvider::class,
            FamilyFormOptionsProvider::class,
        ], $dependencies);
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'beginTransaction', 'new Person', 'new Student', 'new Family'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden));
        }
        assertSameValue(1, substr_count($controller, '$this->createRepresentativeFamily->handle('));
        assertSameValue(1, substr_count($controller, '$this->createStudentInFamily->handle('));
        assertSameValue(false, str_contains($controller, 'catch (Throwable'));
        assertSameValue(false, str_contains($controller, 'catch (RuntimeException'));

        $views = '';
        foreach (glob(dirname(__DIR__) . '/resources/views/families/*.php') ?: [] as $view) {
            $views .= (string) file_get_contents($view);
        }
        assertSameValue(0, preg_match('/antares|ueant/i', $views));
        foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $sqlToken) {
            assertSameValue(false, str_contains($views, $sqlToken));
        }
        assertSameValue(false, str_contains($views, 'RepresentativeStudent'));
        assertSameValue(false, str_contains($views, 'name="username"'));
        assertSameValue(false, str_contains($views, 'name="password"'));
        assertSameValue(2, substr_count($views, 'name="_csrf_token"'));
    });
}

/**
 * @return array{FamilyController, CompositeOrchestrationEnvironment, FakeSessionManager}
 */
function familyDeliveryController(
    ?CompositeOrchestrationEnvironment $environment = null,
    ?PersonFormOptions $personOptions = null,
    ?FamilyFormOptions $familyOptions = null,
    ?FamilyRepository $studentFamilies = null,
): array {
    $environment ??= new CompositeOrchestrationEnvironment();
    $session = new FakeSessionManager();
    $familyOptions ??= new FamilyFormOptions(
        [new FamilyFormOption(11, 'PARENT', 'Parent')],
        [FamilyStatus::Active, FamilyStatus::Inactive],
    );
    $provider = new class($familyOptions) implements FamilyFormOptionsProvider {
        public function __construct(private readonly FamilyFormOptions $options)
        {
        }

        public function get(): FamilyFormOptions
        {
            return $this->options;
        }
    };
    $familyRepository = $studentFamilies ?? $environment->families;

    return [
        new FamilyController(
            $environment->representativeFlow(),
            $environment->studentFlow($familyRepository),
            new GetFamily($familyRepository),
            new FakeDeliveryCsrf(),
            $session,
            new FakePersonFormOptionsProvider($personOptions ?? deliveryOptions()),
            $provider,
        ),
        $environment,
        $session,
    ];
}

/** @return array{FamilyAdministrationMiddleware, FakeSessionManager} */
function familyAdministrationMiddleware(string $identifier): array
{
    $repository = new InMemoryUserRepository(deliveryUser($identifier));
    $session = new FakeSessionManager();
    $session->userId = 1;

    return [
        new FamilyAdministrationMiddleware(new GetAuthenticatedUser($session, $repository)),
        $session,
    ];
}

/** @param array<string, mixed> $overrides */
function familyRepresentativeDeliveryInput(array $overrides = []): array
{
    return array_replace(familyPersonDeliveryInput(), [
        'occupation' => 'Engineer',
        'company_name' => 'Independent',
        'position' => 'Consultant',
        'work_phone' => 'work extension',
        'work_email' => 'work@example.test',
        'representative_status' => RepresentativeStatus::Active->value,
        'display_name' => 'Delivery Family',
        'family_status' => FamilyStatus::Active->value,
        'relationship_type_id' => '11',
        'started_at' => '2026-08-05T10:11',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function familyStudentDeliveryInput(int $familyId, array $overrides = []): array
{
    return array_replace(familyPersonDeliveryInput([
        'first_name' => 'Grace',
        'document_number' => 'DELIVERY-STUDENT-PERSON-001',
        'birth_date' => '2015-03-04',
    ]), [
        'family_id' => (string) $familyId,
        'institutional_code' => 'DELIVERY-STUDENT-001',
        'admission_date' => '2026-08-01',
        'student_status' => StudentStatus::Active->value,
        'started_at' => '2026-08-05T18:19',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function familyPersonDeliveryInput(array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'first_name' => 'Ada',
        'middle_name' => 'Lovelace',
        'first_surname' => 'Byron',
        'second_surname' => '',
        'document_type_id' => '10',
        'document_number' => 'DELIVERY-REP-001',
        'birth_date' => '1990-01-01',
        'sex_id' => '20',
        'marital_status_id' => '30',
        'education_level_id' => '40',
        'email' => 'ada@example.test',
        'mobile_phone' => 'mobile extension',
        'landline_phone' => 'landline extension',
        'person_status' => 'ACTIVE',
    ], $overrides);
}

function familyOpenStudentForm(FamilyController $controller, int $familyId): void
{
    deliveryRequest('GET', '/families/students/create?family_id=' . $familyId, [
        'family_id' => (string) $familyId,
    ]);
    $controller->showCreateStudent();
}

function familySqliteManager(): ConnectionManager
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('pdo_sqlite is required for Family delivery adapter tests.');
    }

    $manager = new ConnectionManager(new ConnectionFactory(), new DatabaseConfig([
        'driver' => 'sqlite',
        'host' => '',
        'port' => 0,
        'database' => ':memory:',
        'username' => '',
        'password' => '',
        'charset' => '',
    ]));
    $property = new ReflectionProperty(ConnectionManager::class, 'connection');
    $property->setValue($manager, new PDO('sqlite::memory:', options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]));

    return $manager;
}
