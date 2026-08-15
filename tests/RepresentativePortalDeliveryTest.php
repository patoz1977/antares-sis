<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\FamilyRepository;
use App\Family\Http\FamilyAdministrationMiddleware;
use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\GetAuthorizedFamilies;
use App\IdentityAccess\Application\LogoutUser;
use App\IdentityAccess\Application\RepresentativeFamilyContextSession;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\IdentityAccess\Application\SelectAuthorizedFamily;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Http\RepresentativePortalController;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use Core\Container\Container;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\AuthenticationMiddleware;
use Core\Security\AuthenticatedUserProviderInterface;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerRepresentativePortalDeliveryTests(TestRunner $runner): void
{
    $runner->add('Representative Portal exposes exactly two authenticated routes', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');

        assertSameValue(1, substr_count($routes, "'/representative',"));
        assertSameValue(1, substr_count($routes, "'/representative/family',"));
        assertSameValue(2, substr_count($routes, '[$representativePortalController,'));
        foreach (['RepresentativePortalController', 'AuthenticationMiddleware::class'] as $required) {
            assertSameValue(true, str_contains($routes, $required), $required);
        }
        foreach ([
            '/representative/students', '/representative/enrollment',
            '/representative/documents', '/representative/profile',
            '/representative/family/change',
        ] as $excluded) {
            assertSameValue(false, str_contains($routes, $excluded), $excluded);
        }

        $portalRoutes = implode("\n", array_filter(
            explode("\n", str_replace(["\r\n", "\r"], "\n", $routes)),
            static fn (string $line): bool => str_contains($line, 'representativePortalController')
                || str_contains($line, "'/representative"),
        ));
        assertSameValue(false, str_contains($portalRoutes, 'PersonAdministrationMiddleware'));
        assertSameValue(false, str_contains($portalRoutes, 'FamilyAdministrationMiddleware'));
    });

    $runner->add('Representative Portal redirects unauthenticated requests through existing middleware', function (): void {
        $nextCalled = false;
        $middleware = new AuthenticationMiddleware(
            new class implements AuthenticatedUserProviderInterface {
                public function check(): bool
                {
                    return false;
                }
            },
        );

        $response = $middleware->handle(new Request(), function () use (&$nextCalled): Response {
            $nextCalled = true;

            return new Response();
        });

        deliverySendResponse($response);
        assertSameValue(302, http_response_code());
        assertSameValue(false, $nextCalled);
    });

    $runner->add('Authenticated non-Representative and Representative without Families fail closed', function (): void {
        $admin = representativePortalFixture(withRepresentative: false, loginIdentifier: 'admin');
        $adminHtml = $admin['controller']->index();
        assertSameValue(403, http_response_code());
        deliveryAssertContains('Representative Portal unavailable', $adminHtml);
        deliveryAssertContains('action="/logout"', $adminHtml);
        $adminPost = representativePortalPost($admin['controller'], 10);
        assertSameValue(403, http_response_code());
        deliveryAssertContains('Representative Portal unavailable', $adminPost);
        assertSameValue(null, $admin['session']->get('representative_family_context_id'));

        $empty = representativePortalFixture();
        $emptyHtml = $empty['controller']->index();
        assertSameValue(403, http_response_code());
        deliveryAssertContains('No family context is currently available.', $emptyHtml);
        deliveryAssertContains('action="/logout"', $emptyHtml);
        assertSameValue(false, str_contains($emptyHtml, 'name="family_id"'));
        assertSameValue(null, $empty['session']->get('representative_family_context_id'));
    });

    $runner->add('One authorized Family enters directly without selector or internal identity', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, '<Only & Family>', [33]));

        $html = $fixture['controller']->index();

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Representative Portal', $html);
        deliveryAssertContains('Current family:', $html);
        deliveryAssertContains('&lt;Only &amp; Family&gt;', $html);
        assertSameValue(false, str_contains($html, '<Only & Family>'));
        assertSameValue(false, str_contains($html, 'name="family_id"'));
        assertSameValue(false, str_contains($html, 'Change family'));
        assertSameValue(false, str_contains($html, '>10<'));
        deliveryAssertContains('action="/logout"', $html);
    });

    $runner->add('Multiple authorized Families render only escaped authorized selection', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Family <A>', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Family & B', [33]));
        $fixture['families']->seed(familyContextFamily(30, 'Other Representative', [99]));

        $html = $fixture['controller']->index();

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Select a family', $html);
        deliveryAssertContains('name="family_id"', $html);
        deliveryAssertContains('Family &lt;A&gt;', $html);
        deliveryAssertContains('Family &amp; B', $html);
        assertSameValue(false, str_contains($html, 'Other Representative'));
        assertSameValue(false, str_contains($html, 'Current family:'));
        assertSameValue(false, str_contains($html, 'Change family'));
    });

    $runner->add('Authorized POST selects and changes Family with a 303 redirect', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Family B', [33]));

        representativePortalPost($fixture['controller'], 10, ['representative_id' => '999']);
        assertSameValue(303, http_response_code());
        assertSameValue(10, $fixture['session']->get('representative_family_context_id'));
        $selected = $fixture['controller']->index();
        deliveryAssertContains('Current family: <strong>Family A</strong>', $selected);
        deliveryAssertContains('Change family', $selected);

        representativePortalPost($fixture['controller'], 20);
        assertSameValue(303, http_response_code());
        assertSameValue(20, $fixture['session']->get('representative_family_context_id'));
        deliveryAssertContains('Current family: <strong>Family B</strong>', $fixture['controller']->index());
        assertSameValue(0, $fixture['families']->saveCalls());
    });

    $runner->add('Invalid CSRF and malformed Family input never change context', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Family B', [33]));
        $fixture['select']->handle(10);

        foreach ([
            ['_csrf_token' => 'invalid', 'family_id' => '20'],
            ['_csrf_token' => 'delivery-csrf'],
            ['_csrf_token' => 'delivery-csrf', 'family_id' => ['20']],
            ['_csrf_token' => 'delivery-csrf', 'family_id' => '0'],
            ['_csrf_token' => 'delivery-csrf', 'family_id' => '-1'],
        ] as $input) {
            deliveryRequest('POST', '/representative/family', $input);
            $html = $fixture['controller']->selectFamily();
            assertSameValue(403, http_response_code());
            deliveryAssertContains('Representative Portal unavailable', $html);
            assertSameValue(10, $fixture['session']->get('representative_family_context_id'));
        }
    });

    $runner->add('Nonexistent unauthorized and historical Families are indistinguishably rejected', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Authorized', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Other Representative', [99]));
        $fixture['families']->seed(familyContextFamily(30, 'Historical', [99], [33]));
        $fixture['controller']->index();

        foreach ([20, 30, 999] as $familyId) {
            $html = representativePortalPost($fixture['controller'], $familyId);
            assertSameValue(403, http_response_code());
            deliveryAssertContains('cannot access the requested', $html);
            assertSameValue(false, str_contains($html, (string) $familyId));
            assertSameValue(10, $fixture['session']->get('representative_family_context_id'));
        }
    });

    $runner->add('Membership ended between render and POST cannot establish context', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Family A', [99, 33]));
        $fixture['families']->seed(familyContextFamily(20, 'Family B', [33]));
        deliveryAssertContains('Family A', $fixture['controller']->index());

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $html = representativePortalPost($fixture['controller'], 10);

        assertSameValue(403, http_response_code());
        deliveryAssertContains('Representative Portal unavailable', $html);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('GET revalidation replaces stale selection with one remaining Family', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Stale Family A', [99, 33]));
        $fixture['families']->seed(familyContextFamily(20, 'Remaining Family B', [33]));
        $fixture['select']->handle(10);

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $html = $fixture['controller']->index();

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Current family: <strong>Remaining Family B</strong>', $html);
        assertSameValue(false, str_contains($html, 'Stale Family A'));
        assertSameValue(false, str_contains($html, 'name="family_id"'));
        assertSameValue(20, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('GET revalidation fails closed when stale context leaves zero Families', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Stale Only Family', [99, 33]));
        $fixture['controller']->index();

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $html = $fixture['controller']->index();

        assertSameValue(403, http_response_code());
        deliveryAssertContains('No family context is currently available.', $html);
        assertSameValue(false, str_contains($html, 'Stale Only Family'));
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('GET revalidation restores selector when stale context leaves multiple Families', function (): void {
        $fixture = representativePortalFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Stale Family A', [99, 33]));
        $fixture['families']->seed(familyContextFamily(20, 'Remaining Family B', [33]));
        $fixture['families']->seed(familyContextFamily(30, 'Remaining Family C', [33]));
        $fixture['select']->handle(10);

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $html = $fixture['controller']->index();

        deliveryAssertContains('Select a family', $html);
        deliveryAssertContains('Remaining Family B', $html);
        deliveryAssertContains('Remaining Family C', $html);
        assertSameValue(false, str_contains($html, 'Stale Family A'));
        assertSameValue(false, str_contains($html, 'Current family:'));
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Representative login dispatches through root while admin keeps dashboard', function (): void {
        $representative = representativePortalLoginFixture(true, 'representative-login');
        $representative['families']->seed(familyContextFamily(10, 'Login Family', [33]));
        deliveryRequest('POST', '/login', [
            '_csrf_token' => 'delivery-csrf',
            'username' => 'representative-login',
            'password' => 'portal-password',
        ]);
        assertSameValue('', $representative['authentication']->login());
        assertSameValue(303, http_response_code());
        assertSameValue(11, $representative['session']->authenticatedUserId());

        deliveryRequest('GET', '/');
        assertSameValue('', $representative['authentication']->dashboard());
        assertSameValue(302, http_response_code());
        deliveryRequest('GET', '/representative');
        deliveryAssertContains('Login Family', $representative['portal']->index());

        $admin = representativePortalLoginFixture(false, 'admin');
        deliveryRequest('POST', '/login', [
            '_csrf_token' => 'delivery-csrf',
            'username' => 'admin',
            'password' => 'portal-password',
        ]);
        $admin['authentication']->login();
        deliveryRequest('GET', '/');
        $dashboard = $admin['authentication']->dashboard();
        assertSameValue(200, http_response_code());
        deliveryAssertContains('Dashboard', $dashboard);
        deliveryAssertContains('Manage Persons', $dashboard);
        assertSameValue(false, str_contains($dashboard, '/representative'));
        assertSameValue(403, representativePortalStatus($admin['portal']));

        $formerRepresentative = representativePortalLoginFixture(false, 'former-representative');
        $formerRepresentative['session']->userId = 11;
        deliveryRequest('GET', '/');
        $formerDashboard = $formerRepresentative['authentication']->dashboard();
        deliveryAssertContains('Dashboard', $formerDashboard);
        assertSameValue(false, str_contains($formerDashboard, '/representative'));
    });

    $runner->add('Representative Portal access does not grant administrative routes', function (): void {
        $fixture = representativePortalLoginFixture(true, 'representative-login');
        $fixture['session']->userId = 11;
        $next = static fn (Request $request): Response => (new Response())->content('not-allowed');

        foreach ([
            new PersonAdministrationMiddleware($fixture['getUser']),
            new FamilyAdministrationMiddleware($fixture['getUser']),
        ] as $middleware) {
            $response = $middleware->handle(new Request(), $next);
            assertSameValue('Forbidden', deliverySendResponse($response));
            assertSameValue(403, http_response_code());
        }
    });

    $runner->add('Login regeneration isolates Family context between Representatives', function (): void {
        $session = new FakeSessionManager();
        $users = new InMemoryRepresentativeUserRepository();
        $users->seed(representativePortalUser(11, 22, 'representative-a'));
        $users->seed(representativePortalUser(12, 44, 'representative-b'));
        $session->userId = 11;
        $representatives = representativePortalMappedRepresentatives();
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyContextFamily(10, 'Family A', [33]));
        $families->seed(familyContextFamily(20, 'Family B', [55]));
        $getUser = new GetAuthenticatedUser($session, $users);
        $getRepresentative = new GetAuthenticatedRepresentative($getUser, $representatives);
        $getFamilies = new GetAuthorizedFamilies($getRepresentative, $families);
        $contextSession = new RepresentativeFamilyContextSession($session);
        $resolve = new ResolveFamilyContext($getFamilies, $contextSession);

        assertSameValue(10, $resolve->handle()?->context?->familyId);
        $session->regenerateForUser(12);
        $accessB = $resolve->handle();

        assertSameValue(20, $accessB?->context?->familyId);
        assertSameValue([20], array_map(
            static fn ($family): int => $family->familyId,
            $accessB?->authorizedFamilies ?? [],
        ));
        assertSameValue(20, $session->get('representative_family_context_id'));
        assertSameValue(null, $session->get('representative_id'));
        assertSameValue(null, $session->get('person_id'));
    });

    $runner->add('Representative Portal remains thin White Label Delivery with approved wiring', function (): void {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/RepresentativePortalController.php'
        );
        foreach ([
            'PDO', 'Repository', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ',
            'Transaction', 'FamilyStatus', 'RepresentativeId', 'PersonId',
        ] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        foreach (['ResolveFamilyContext', 'SelectAuthorizedFamily', 'CsrfTokenManager'] as $dependency) {
            assertSameValue(true, str_contains($controller, $dependency), $dependency);
        }

        $views = '';
        foreach (['index.php', 'forbidden.php', 'no-family.php'] as $view) {
            $views .= (string) file_get_contents(
                dirname(__DIR__) . '/resources/views/representative-portal/' . $view
            );
        }
        assertSameValue(0, preg_match('/antares|ueant|colegio/i', $views));
        foreach ([
            'Enrollment', 'Student', 'Address', 'Emergency', 'Pickup',
            'Submission', 'SELECT ', 'Repository',
        ] as $excluded) {
            assertSameValue(false, str_contains($views, $excluded), $excluded);
        }
        assertSameValue(true, str_contains($views, 'htmlspecialchars'));

        $fixture = representativePortalFixture();
        $container = new Container();
        $container->instance(ResolveFamilyContext::class, $fixture['resolve']);
        $container->instance(SelectAuthorizedFamily::class, $fixture['select']);
        $container->instance(
            \App\IdentityAccess\Application\Contract\CsrfTokenManager::class,
            new FakeDeliveryCsrf(),
        );
        $container->instance(
            \App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState::class,
            $fixture['acknowledgements']['state'],
        );
        $container->singleton(RepresentativePortalController::class, RepresentativePortalController::class);
        assertSameValue(
            true,
            $container->make(RepresentativePortalController::class) instanceof RepresentativePortalController,
        );

        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        assertSameValue(true, str_contains(
            $bootstrap,
            '$container->singleton(RepresentativePortalController::class, RepresentativePortalController::class);',
        ));
        $authenticationController = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/AuthenticationController.php'
        );
        assertSameValue(true, str_contains(
            $authenticationController,
            "return \$this->redirect('/representative');",
        ));
        assertSameValue(4, count((new ReflectionClass(RepresentativePortalController::class))
            ->getConstructor()?->getParameters() ?? []));
    });
}

/** @return array<string, mixed> */
function representativePortalFixture(
    bool $withRepresentative = true,
    string $loginIdentifier = 'representative-22',
    array $acknowledgementRequirements = [],
    ?array $academicPeriods = null,
): array {
    $fixture = familyContextAuthorizationFixture(
        withRepresentative: $withRepresentative,
        loginIdentifier: $loginIdentifier,
    );
    $fixture['acknowledgements'] = representativeAcknowledgementTestServices(
        $fixture['getRepresentative'],
        $acknowledgementRequirements,
        $academicPeriods,
    );
    $fixture['controller'] = new RepresentativePortalController(
        $fixture['resolve'],
        $fixture['select'],
        new FakeDeliveryCsrf(),
        $fixture['acknowledgements']['state'],
    );

    return $fixture;
}

/** @param array<string, mixed> $extra */
function representativePortalPost(
    RepresentativePortalController $controller,
    int $familyId,
    array $extra = [],
): string {
    deliveryRequest('POST', '/representative/family', array_merge([
        '_csrf_token' => 'delivery-csrf',
        'family_id' => (string) $familyId,
    ], $extra));

    return $controller->selectFamily();
}

/** @return array<string, mixed> */
function representativePortalLoginFixture(bool $withRepresentative, string $loginIdentifier): array
{
    $session = new FakeSessionManager();
    $users = new InMemoryRepresentativeUserRepository();
    $users->seed(representativePortalUser(11, 22, $loginIdentifier, true));
    $representative = $withRepresentative
        ? new Representative(
            new RepresentativeId(33),
            new PersonId(22),
            null,
            RepresentativeStatus::Active,
        )
        : null;
    $representatives = new RepresentativeAccessResolutionTest($representative);
    $getUser = new GetAuthenticatedUser($session, $users);
    $getRepresentative = new GetAuthenticatedRepresentative($getUser, $representatives);
    $hasher = new NativePasswordHasher();
    $events = new FakeSecurityEvents();
    $authenticate = new AuthenticateUser(
        $users,
        $hasher,
        $session,
        new ImmediateTransactionManager(),
        new FrozenClock(familyContextInstant('2026-08-10 12:00:00')),
        $events,
        new AuthenticationPolicy(5, 900),
    );
    $families = new InMemoryFamilyApplicationRepository();
    $getFamilies = new GetAuthorizedFamilies($getRepresentative, $families);
    $contextSession = new RepresentativeFamilyContextSession($session);
    $resolve = new ResolveFamilyContext($getFamilies, $contextSession);
    $select = new SelectAuthorizedFamily($getFamilies, $contextSession);
    $acknowledgements = representativeAcknowledgementTestServices($getRepresentative);

    return [
        'authentication' => new AuthenticationController(
            $authenticate,
            new LogoutUser($session, $events),
            $getUser,
            $getRepresentative,
            new FakeDeliveryCsrf(),
            $session,
        ),
        'portal' => new RepresentativePortalController(
            $resolve,
            $select,
            new FakeDeliveryCsrf(),
            $acknowledgements['state'],
        ),
        'session' => $session,
        'users' => $users,
        'getUser' => $getUser,
        'getRepresentative' => $getRepresentative,
        'families' => $families,
    ];
}

function representativePortalUser(
    int $userId,
    int $personId,
    string $identifier,
    bool $withRealPassword = false,
): User {
    return new User(
        new UserId($userId),
        new UserPersonId($personId),
        new LoginIdentifier($identifier),
        new PasswordHash($withRealPassword
            ? (new NativePasswordHasher())->hash('portal-password')
            : 'safe-hash'),
        UserStatus::Active,
    );
}

function representativePortalMappedRepresentatives(): RepresentativeRepository
{
    $representatives = [
        22 => new Representative(
            new RepresentativeId(33),
            new PersonId(22),
            null,
            RepresentativeStatus::Active,
        ),
        44 => new Representative(
            new RepresentativeId(55),
            new PersonId(44),
            null,
            RepresentativeStatus::Active,
        ),
    ];

    return new class($representatives) implements RepresentativeRepository {
        /** @param array<int, Representative> $representatives */
        public function __construct(private readonly array $representatives)
        {
        }

        public function findById(RepresentativeId $id): ?Representative
        {
            foreach ($this->representatives as $representative) {
                if ($representative->id()?->equals($id)) {
                    return clone $representative;
                }
            }

            return null;
        }

        public function findByPersonId(PersonId $personId): ?Representative
        {
            return isset($this->representatives[$personId->value()])
                ? clone $this->representatives[$personId->value()]
                : null;
        }

        public function save(Representative $representative): Representative
        {
            return clone $representative;
        }
    };
}

function representativePortalStatus(RepresentativePortalController $controller): int
{
    deliveryRequest('GET', '/representative');
    $controller->index();

    return http_response_code();
}
