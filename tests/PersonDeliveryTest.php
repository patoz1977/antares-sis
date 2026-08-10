<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\LogoutUser;
use App\IdentityAccess\Application\Orchestration\UpdatePersonWithRepresentativeUserSync;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Application\CreatePerson;
use App\Person\Application\GetPerson;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use App\Person\Http\PersonFormOption;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthenticationMiddleware;
use Core\Security\AuthenticatedUserProviderInterface;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestRunner;

function registerPersonDeliveryTests(TestRunner $runner): void
{
    $runner->add('Person delivery redirects unauthenticated users through authentication middleware', function (): void {
        $nextCalled = false;
        $middleware = new AuthenticationMiddleware(
            new class implements AuthenticatedUserProviderInterface {
                public function check(): bool
                {
                    return false;
                }
            }
        );

        $response = $middleware->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response();
        });

        deliverySendResponse($response);
        assertSameValue(302, http_response_code());
        assertSameValue(false, $nextCalled);

        $source = (string) file_get_contents(dirname(__DIR__) . '/core/Middleware/AuthenticationMiddleware.php');
        assertSameValue(true, str_contains($source, "Location: /login"));
    });

    $runner->add('Person administration rejects authenticated non-admin users with 403', function (): void {
        [$middleware] = deliveryAdministrationMiddleware('operator');
        $_GET = ['login_identifier' => 'admin'];
        $_POST = ['login_identifier' => 'admin'];
        $nextCalled = false;

        $response = $middleware->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response();
        });

        $content = deliverySendResponse($response);
        assertSameValue(403, http_response_code());
        assertSameValue(false, $nextCalled);
        assertSameValue('Forbidden', $content);
    });

    $runner->add('Person administration allows only the authenticated admin identity', function (): void {
        [$middleware] = deliveryAdministrationMiddleware('admin');
        $nextCalled = false;

        $response = $middleware->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return (new Response())->content('allowed');
        });

        $content = deliverySendResponse($response);
        assertSameValue(200, http_response_code());
        assertSameValue(true, $nextCalled);
        assertSameValue('allowed', $content);
    });

    $runner->add('dashboard exposes Person navigation only to admin', function (): void {
        $admin = deliveryDashboardController('admin')->dashboard();
        $operator = deliveryDashboardController('operator')->dashboard();

        assertSameValue(true, str_contains($admin, 'href="/persons"'));
        assertSameValue(false, str_contains($operator, 'href="/persons"'));
    });

    $runner->add('Person index and create form provide minimal navigation and CSRF', function (): void {
        [$controller] = deliveryController();

        $index = $controller->index();
        $form = $controller->showCreate();

        deliveryAssertContains('Create Person', $index);
        deliveryAssertContains('action="/persons/show"', $index);
        deliveryAssertContains('name="_csrf_token" value="delivery-csrf"', $form);
        deliveryAssertContains('action="/persons/create"', $form);
    });

    $runner->add('Person detail accepts a positive ID and missing Person is safe', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(7));

        deliveryRequest('GET', '/persons/show?id=7', ['id' => '7']);
        $found = $controller->show();
        deliveryAssertContains('Person details', $found);
        deliveryAssertContains('Stored', $found);

        deliveryRequest('GET', '/persons/show?id=999', ['id' => '999']);
        $missing = $controller->show();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Person not found', $missing);
        assertSameValue(false, str_contains($missing, 'SQL'));
    });

    $runner->add('Person detail rejects invalid IDs through the safe index flow', function (): void {
        [$controller] = deliveryController();
        deliveryRequest('GET', '/persons/show?id=invalid', ['id' => 'invalid']);

        assertSameValue('', $controller->show());
        assertSameValue(302, http_response_code());

        $index = $controller->index();
        deliveryAssertContains('valid positive Person ID', $index);
    });

    $runner->add('valid Person creation invokes Application and redirects to detail', function (): void {
        [$controller, $repository] = deliveryController();
        deliveryRequest('POST', '/persons/create', deliveryInput());

        assertSameValue('', $controller->create());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $repository->saveCalls());
        assertSameValue(100, $repository->lastSaved()?->id()?->value());
    });

    $runner->add('incomplete identification is rejected before Person creation', function (): void {
        [$controller, $repository] = deliveryController();
        $input = deliveryInput();
        $input['document_number'] = '';
        deliveryRequest('POST', '/persons/create', $input);

        $response = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('must both be provided', $response);
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('Person domain errors preserve escaped input without internal details', function (): void {
        [$controller, $repository] = deliveryController();
        $input = deliveryInput();
        $input['first_name'] = '<script>alert(1)</script>';
        $input['birth_date'] = '2999-01-01';
        deliveryRequest('POST', '/persons/create', $input);

        $response = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('Review the entered Person data.', $response);
        deliveryAssertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $response);
        assertSameValue(false, str_contains($response, '<script>'));
        assertSameValue(false, str_contains($response, 'SQLSTATE'));
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('duplicate Person identification returns a safe form error', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(12, 'DOC-1'));
        deliveryRequest('POST', '/persons/create', deliveryInput());

        $response = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('already uses that identification', $response);
        assertSameValue(false, str_contains($response, 'identification_key'));
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('invalid CSRF prevents Person creation and preserves safe form data', function (): void {
        [$controller, $repository] = deliveryController();
        $input = deliveryInput();
        $input['_csrf_token'] = 'invalid';
        deliveryRequest('POST', '/persons/create', $input);

        assertSameValue('', $controller->create());
        assertSameValue(303, http_response_code());
        assertSameValue(0, $repository->saveCalls());

        $form = $controller->showCreate();
        deliveryAssertContains('Your form expired', $form);
        deliveryAssertContains('value="Ada"', $form);
    });

    $runner->add('empty required catalogs disable Person persistence', function (): void {
        [$controller, $repository] = deliveryController(deliveryEmptyOptions());

        $form = $controller->showCreate();
        deliveryAssertContains('required form catalogs are unavailable', $form);
        deliveryAssertContains('type="submit" disabled', $form);

        deliveryRequest('POST', '/persons/create', deliveryInput());
        $response = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('required form catalogs are unavailable', $response);
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('edit loads current Person data and valid update persists it', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(21));
        deliveryRequest('GET', '/persons/edit?id=21', ['id' => '21']);

        $edit = $controller->showEdit();
        deliveryAssertContains('value="Stored"', $edit);
        deliveryAssertContains('name="id" value="21"', $edit);
        deliveryAssertContains('name="_csrf_token"', $edit);

        $input = deliveryInput(['id' => '21', 'first_name' => 'Updated']);
        deliveryRequest('POST', '/persons/update', $input);
        assertSameValue('', $controller->update());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $repository->saveCalls());
        assertSameValue('Updated', $repository->findById(new PersonId(21))?->personalName()->firstName());
    });

    $runner->add('Person update removes optional data', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(22));
        deliveryRequest('GET', '/persons/edit?id=22', ['id' => '22']);
        $controller->showEdit();

        $input = deliveryInput([
            'id' => '22',
            'document_type_id' => '',
            'document_number' => '',
            'marital_status_id' => '',
            'education_level_id' => '',
            'email' => '',
            'mobile_phone' => '',
            'landline_phone' => '',
        ]);
        deliveryRequest('POST', '/persons/update', $input);
        $controller->update();

        $stored = $repository->findById(new PersonId(22));
        assertSameValue(null, $stored?->identification());
        assertSameValue(null, $stored?->maritalStatusId());
        assertSameValue(null, $stored?->educationLevelId());
        assertSameValue(null, $stored?->contactInformation());
    });

    $runner->add('Person update identity is bound to the server-side edit session', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(30));
        $repository->seed(deliveryPerson(31, 'DOC-31'));
        deliveryRequest('GET', '/persons/edit?id=30', ['id' => '30']);
        $controller->showEdit();

        deliveryRequest('POST', '/persons/update', deliveryInput(['id' => '31', 'first_name' => 'Tampered']));
        $response = $controller->update();

        assertSameValue(422, http_response_code());
        deliveryAssertContains('identity cannot be changed', $response);
        assertSameValue(0, $repository->saveCalls());
        assertSameValue('Stored', $repository->findById(new PersonId(30))?->personalName()->firstName());
        assertSameValue('Stored', $repository->findById(new PersonId(31))?->personalName()->firstName());
    });

    $runner->add('invalid CSRF prevents Person update', function (): void {
        [$controller, $repository] = deliveryController();
        $repository->seed(deliveryPerson(40));
        deliveryRequest('GET', '/persons/edit?id=40', ['id' => '40']);
        $controller->showEdit();

        deliveryRequest('POST', '/persons/update', deliveryInput(['id' => '40', '_csrf_token' => 'invalid']));
        assertSameValue('', $controller->update());
        assertSameValue(303, http_response_code());
        assertSameValue(0, $repository->saveCalls());
    });

    $runner->add('Person delivery synchronizes Representative username when document changes', function (): void {
        [$controller, $persons, $users] = deliveryControllerWithRepresentativeUser();
        deliveryRequest('GET', '/persons/edit?id=60', ['id' => '60']);
        $controller->showEdit();

        deliveryRequest('POST', '/persons/update', deliveryInput([
            'id' => '60',
            'document_number' => 'NEW-REP-60',
        ]));
        assertSameValue('', $controller->update());
        assertSameValue(303, http_response_code());
        assertSameValue(
            'NEW-REP-60',
            $persons->findById(new PersonId(60))?->identification()?->documentNumber(),
        );
        assertSameValue(
            'new-rep-60',
            $users->findByPersonId(new UserPersonId(60))?->loginIdentifier()->value(),
        );
    });

    $runner->add('Person delivery safely rejects Identification removal for Representative User', function (): void {
        [$controller, $persons, $users] = deliveryControllerWithRepresentativeUser();
        deliveryRequest('GET', '/persons/edit?id=60', ['id' => '60']);
        $controller->showEdit();

        deliveryRequest('POST', '/persons/update', deliveryInput([
            'id' => '60',
            'document_type_id' => '',
            'document_number' => '',
        ]));
        $html = $controller->update();

        assertSameValue(422, http_response_code());
        deliveryAssertContains('must retain complete identification', $html);
        assertSameValue('REP-60', $persons->findById(new PersonId(60))?->identification()?->documentNumber());
        assertSameValue('rep-60', $users->findByPersonId(new UserPersonId(60))?->loginIdentifier()->value());
    });

    $runner->add('Person delivery leaves no partial state when synchronized User save fails', function (): void {
        [$controller, $persons, $users] = deliveryControllerWithRepresentativeUser();
        deliveryRequest('GET', '/persons/edit?id=60', ['id' => '60']);
        $controller->showEdit();
        $users->failNextSave(new \RuntimeException('simulated User write failure'));
        deliveryRequest('POST', '/persons/update', deliveryInput([
            'id' => '60',
            'first_name' => 'Partial',
            'document_number' => 'PARTIAL-REP-60',
        ]));

        assertThrows(fn () => $controller->update(), \RuntimeException::class);
        assertSameValue('Stored', $persons->findById(new PersonId(60))?->personalName()->firstName());
        assertSameValue('REP-60', $persons->findById(new PersonId(60))?->identification()?->documentNumber());
        assertSameValue('rep-60', $users->findByPersonId(new UserPersonId(60))?->loginIdentifier()->value());
    });

    $runner->add('Person delivery routes enforce both middleware and expose no delete flow', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        foreach ([
            "get('/persons'",
            "get('/persons/create'",
            "post('/persons/create'",
            "get('/persons/show'",
            "get('/persons/edit'",
            "post('/persons/update'",
        ] as $route) {
            assertSameValue(true, str_contains($routes, $route), sprintf('Missing route %s.', $route));
        }
        assertSameValue(true, str_contains($routes, 'AuthenticationMiddleware::class'));
        assertSameValue(true, str_contains($routes, 'PersonAdministrationMiddleware::class'));
        assertSameValue(6, substr_count($routes, '], $personMiddleware);'));
        assertSameValue(false, str_contains($routes, '/persons/delete'));
        assertSameValue(false, method_exists(PersonController::class, 'delete'));
    });

    $runner->add('Person delivery keeps PDO out of HTTP and remains White Label', function (): void {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Person/Http/PersonController.php');
        assertSameValue(false, str_contains($controller, 'PDO'));
        assertSameValue(false, str_contains($controller, 'SQLSTATE'));

        $viewSource = '';
        foreach (glob(dirname(__DIR__) . '/resources/views/persons/*.php') ?: [] as $view) {
            $viewSource .= (string) file_get_contents($view);
        }
        assertSameValue(0, preg_match('/antares|ueant/i', $viewSource));

        $provider = (string) file_get_contents(
            dirname(__DIR__) . '/app/Person/Infrastructure/Persistence/PdoPersonFormOptionsProvider.php'
        );
        assertSameValue(true, str_contains($provider, 'WHERE is_active = TRUE'));
        assertSameValue(true, str_contains($provider, "st.code = :statusTypeCode"));
        assertSameValue(true, str_contains($provider, "'GENERAL_STATUS'"));
    });
}

/** @return array{PersonController, InMemoryPersonApplicationRepository, FakeSessionManager} */
function deliveryController(?PersonFormOptions $options = null): array
{
    $repository = new InMemoryPersonApplicationRepository(deliveryToday());
    $users = new InMemoryRepresentativeUserRepository();
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $transactions = new InMemoryCompositeTransactionRunner([$repository, $users]);
    $session = new FakeSessionManager();
    $controller = new PersonController(
        new CreatePerson($repository),
        new GetPerson($repository),
        new UpdatePersonWithRepresentativeUserSync(
            new UpdatePerson($repository),
            $repository,
            $users,
            $representatives,
            $transactions,
        ),
        new FakePersonFormOptionsProvider($options ?? deliveryOptions()),
        new FakeDeliveryCsrf(),
        $session,
    );

    return [$controller, $repository, $session];
}

/** @return array{PersonController, InMemoryPersonApplicationRepository, InMemoryRepresentativeUserRepository} */
function deliveryControllerWithRepresentativeUser(): array
{
    $repository = new InMemoryPersonApplicationRepository(deliveryToday());
    $repository->seed(deliveryPerson(60, 'REP-60'));
    $users = new InMemoryRepresentativeUserRepository();
    $users->seed(new User(
        new UserId(60),
        new UserPersonId(60),
        new LoginIdentifier('rep-60'),
        new PasswordHash((new NativePasswordHasher())->hash('preserved-password')),
        UserStatus::Active,
        2,
        null,
        deliveryToday()->modify('-1 day'),
    ));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $representatives->seed(representativeUserRepresentative(600, 60));
    $transactions = new InMemoryCompositeTransactionRunner([$repository, $users]);
    $controller = new PersonController(
        new CreatePerson($repository),
        new GetPerson($repository),
        new UpdatePersonWithRepresentativeUserSync(
            new UpdatePerson($repository),
            $repository,
            $users,
            $representatives,
            $transactions,
        ),
        new FakePersonFormOptionsProvider(deliveryOptions()),
        new FakeDeliveryCsrf(),
        new FakeSessionManager(),
    );

    return [$controller, $repository, $users];
}

/** @return array{PersonAdministrationMiddleware, FakeSessionManager} */
function deliveryAdministrationMiddleware(string $identifier): array
{
    $repository = new InMemoryUserRepository(deliveryUser($identifier));
    $session = new FakeSessionManager();
    $session->userId = 1;

    return [
        new PersonAdministrationMiddleware(new GetAuthenticatedUser($session, $repository)),
        $session,
    ];
}

function deliveryDashboardController(string $identifier): AuthenticationController
{
    $repository = new InMemoryUserRepository(deliveryUser($identifier));
    $session = new FakeSessionManager();
    $session->userId = 1;
    $events = new FakeSecurityEvents();
    $hasher = new NativePasswordHasher();
    $authenticate = new AuthenticateUser(
        $repository,
        $hasher,
        $session,
        new ImmediateTransactionManager(),
        new FrozenClock(deliveryToday()),
        $events,
        new AuthenticationPolicy(5, 900),
    );

    $getAuthenticatedUser = new GetAuthenticatedUser($session, $repository);

    return new AuthenticationController(
        $authenticate,
        new LogoutUser($session, $events),
        $getAuthenticatedUser,
        new GetAuthenticatedRepresentative(
            $getAuthenticatedUser,
            new RepresentativeAccessResolutionTest(null),
        ),
        new FakeDeliveryCsrf(),
        $session,
    );
}

function deliveryUser(string $identifier): User
{
    return new User(
        new UserId(1),
        new UserPersonId(1),
        new LoginIdentifier($identifier),
        new PasswordHash(password_hash('delivery-password', PASSWORD_DEFAULT)),
        UserStatus::Active,
    );
}

function deliveryOptions(): PersonFormOptions
{
    return new PersonFormOptions(
        [new PersonFormOption(10, 'DOC', 'Document')],
        [new PersonFormOption(20, 'SEX', 'Sex')],
        [new PersonFormOption(30, 'MARITAL', 'Marital status')],
        [new PersonFormOption(40, 'EDUCATION', 'Education level')],
        [
            new PersonFormOption(50, 'ACTIVE', 'Active'),
            new PersonFormOption(51, 'INACTIVE', 'Inactive'),
        ],
    );
}

function deliveryEmptyOptions(): PersonFormOptions
{
    return new PersonFormOptions([], [], [], [], []);
}

/** @param array<string, mixed> $overrides */
function deliveryInput(array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'first_name' => 'Ada',
        'middle_name' => 'Lovelace',
        'first_surname' => 'Byron',
        'second_surname' => '',
        'document_type_id' => '10',
        'document_number' => 'DOC-1',
        'birth_date' => '1990-01-01',
        'sex_id' => '20',
        'marital_status_id' => '30',
        'education_level_id' => '40',
        'email' => 'ada@example.test',
        'mobile_phone' => 'mobile extension',
        'landline_phone' => 'landline extension',
        'status' => 'ACTIVE',
    ], $overrides);
}

function deliveryPerson(int $id, string $documentNumber = 'DOC-1'): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Stored', null, 'Person', null),
        new Identification(10, $documentNumber),
        new DateTimeImmutable('1990-01-01', new DateTimeZone('UTC')),
        20,
        30,
        40,
        new ContactInformation('stored@example.test', 'stored mobile', 'stored landline'),
        PersonStatus::Active,
        deliveryToday(),
    );
}

function deliveryToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC'));
}

/** @param array<string, mixed> $data */
function deliveryRequest(string $method, string $uri, array $data = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $method === 'GET' ? $data : [];
    $_POST = $method === 'POST' ? $data : [];
    http_response_code(200);
}

function deliverySendResponse(Response $response): string
{
    http_response_code(200);
    ob_start();
    $response->send();

    return (string) ob_get_clean();
}

function deliveryAssertContains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException(sprintf('Expected output to contain "%s".', $needle));
    }
}

final readonly class FakePersonFormOptionsProvider implements PersonFormOptionsProvider
{
    public function __construct(private PersonFormOptions $options)
    {
    }

    public function get(): PersonFormOptions
    {
        return $this->options;
    }
}

final class FakeDeliveryCsrf implements CsrfTokenManager
{
    public function token(): string
    {
        return 'delivery-csrf';
    }

    public function isValid(string $token): bool
    {
        return $token === $this->token();
    }
}
