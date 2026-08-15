<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\ActivateAcademicPeriod;
use App\AcademicCore\Application\DeactivateAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId as CoreAcademicPeriodId;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\InstitutionalDocuments\Application\ActivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\CreateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\DeactivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\GetAcknowledgementRequirements;
use App\InstitutionalDocuments\Application\UpdateAcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOption;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementController;
use App\InstitutionalDocuments\Http\InstitutionalDocumentsAdministrationMiddleware;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use Core\Http\Request;
use Core\Http\Response;
use Tests\Support\TestRunner;

function registerInstitutionalAcknowledgementsAdministratorDeliveryTests(TestRunner $runner): void
{
    $runner->add('E009 administrator gate permits only the temporary exact admin identifier', function (): void {
        foreach ([['operator', 403, false], ['admin', 200, true]] as [$identifier, $status, $expectedCall]) {
            $users = new InMemoryUserRepository(deliveryUser($identifier));
            $session = new FakeSessionManager();
            $session->userId = 1;
            $gate = new InstitutionalDocumentsAdministrationMiddleware(new GetAuthenticatedUser($session, $users));
            $called = false;
            $response = $gate->handle(new Request(), function () use (&$called): Response {
                $called = true;

                return new Response();
            });
            $statusCode = new \ReflectionProperty(Response::class, 'statusCode');
            assertSameValue($status, $statusCode->getValue($response));
            assertSameValue($expectedCall, $called);
        }
    });

    $runner->add('E009 AcademicPeriod PDO provider lists every status deterministically and revalidates IDs', function (): void {
        $manager = familySqliteManager();
        $pdo = $manager->connection();
        $pdo->exec('CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE academic_periods (id INTEGER PRIMARY KEY, code TEXT, name TEXT, starts_on TEXT, ends_on TEXT, status_id INTEGER)');
        $pdo->exec("INSERT INTO status_types VALUES (1, 'GENERAL_STATUS')");
        $pdo->exec("INSERT INTO statuses VALUES (1, 1, 'ACTIVE'), (2, 1, 'INACTIVE')");
        $pdo->exec("INSERT INTO academic_periods VALUES "
            . "(1, 'OLD', 'Old inactive', '2024-09-01', '2025-06-30', 2), "
            . "(2, 'NEW-A', 'New active', '2026-09-01', '2027-06-30', 1), "
            . "(3, 'NEW-B', 'New inactive', '2026-09-01', '2027-07-31', 2)");

        $provider = new PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider($manager);
        assertSameValue(
            [[3, 'NEW-B'], [2, 'NEW-A'], [1, 'OLD']],
            array_map(static fn ($period): array => [$period->id, $period->code], $provider->all()),
        );
        assertSameValue('Old inactive', $provider->findById(1)?->name);
        assertSameValue('ACTIVE', $provider->findById(2)?->status);
        assertSameValue(null, $provider->findById(999));
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/InstitutionalDocuments/Infrastructure/Persistence/PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider.php');
        deliveryAssertContains('prepare(', $source);
        assertSameValue(false, str_contains($source, 'CURRENT_DATE'));
    });

    $runner->add('E009 administrator GET requires explicit revalidated AcademicPeriod context', function (): void {
        [$controller, $repository, $session] = institutionalAcknowledgementDeliveryController();
        $session->put('_institutional_acknowledgements_trusted_academic_period_id', 9);
        deliveryRequest('GET', '/institutional-acknowledgements');
        $selector = $controller->index();
        assertSameValue(null, $session->get('_institutional_acknowledgements_trusted_academic_period_id'));
        assertSameValue(0, $repository->findByPeriodCount);
        deliveryAssertContains('Select an Academic Period', $selector);
        assertSameValue(false, str_contains($selector, 'Period A Requirement'));

        deliveryRequest('GET', '/institutional-acknowledgements?academic_period_id=9', ['academic_period_id' => '9']);
        $page = $controller->index();
        assertSameValue(9, $session->get('_institutional_acknowledgements_trusted_academic_period_id'));
        deliveryAssertContains('Period A Requirement', $page);
        assertSameValue(false, str_contains($page, 'Period B Requirement'));

        deliveryRequest('GET', '/institutional-acknowledgements?academic_period_id=bad', ['academic_period_id' => 'bad']);
        deliveryAssertContains('Select a valid Academic Period', $controller->index());
        assertSameValue(422, http_response_code());
        assertSameValue(null, $session->get('_institutional_acknowledgements_trusted_academic_period_id'));

        deliveryRequest('GET', '/institutional-acknowledgements?academic_period_id=999', ['academic_period_id' => '999']);
        deliveryAssertContains('Academic Period not found', $controller->index());
        assertSameValue(404, http_response_code());
    });

    $runner->add('E009 administrator creates ACTIVE and INACTIVE Requirements with trusted context and PRG', function (): void {
        [$controller, $repository, $session] = institutionalAcknowledgementDeliveryController();
        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/create', institutionalAcknowledgementPost([
            'title' => 'Política ñ', 'url' => 'custom:resource&one', 'official_reference' => '', 'status' => 'ACTIVE',
        ]));
        assertSameValue('', $controller->create());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $repository->saveCount);
        assertSameValue(9, $repository->findById(new AcknowledgementRequirementId(100))?->academicPeriodId()->value());
        assertSameValue(null, $repository->findById(new AcknowledgementRequirementId(100))?->officialReference());
        assertSameValue('Requirement created successfully.', $session->get('_flash_institutional_acknowledgements_success'));

        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/create', institutionalAcknowledgementPost([
            'title' => 'Inactive', 'url' => 'relative/url', 'official_reference' => 'REF', 'status' => 'INACTIVE',
        ]));
        $controller->create();
        assertSameValue('INACTIVE', $repository->findById(new AcknowledgementRequirementId(101))?->status()->value);
    });

    $runner->add('E009 administrator rejects CSRF stale hidden context and invalid input without writes', function (): void {
        foreach ([
            ['_csrf_token' => 'invalid'],
            ['academic_period_id' => '10'],
            ['title' => ''],
            ['status' => 'UNKNOWN'],
            ['url' => ['not scalar']],
        ] as $override) {
            [$controller, $repository, $session] = institutionalAcknowledgementDeliveryController();
            institutionalAcknowledgementOpen($controller, 9);
            deliveryRequest('POST', '/institutional-acknowledgements/requirements/create', institutionalAcknowledgementPost($override));
            $response = $controller->create();
            assertSameValue(0, $repository->saveCount);
            assertSameValue(9, $session->get('_institutional_acknowledgements_trusted_academic_period_id'));
            assertSameValue(false, str_contains($response, 'SQLSTATE'));
        }
    });

    $runner->add('E009 administrator updates and changes status only inside the trusted AcademicPeriod', function (): void {
        [$controller, $repository] = institutionalAcknowledgementDeliveryController();
        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/update', institutionalAcknowledgementPost([
            'requirement_id' => '1', 'title' => 'Updated A', 'url' => 'updated/url', 'official_reference' => 'NEW',
        ]));
        $controller->update();
        assertSameValue(303, http_response_code());
        assertSameValue('Updated A', $repository->findById(new AcknowledgementRequirementId(1))?->title()->value());

        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/deactivate', institutionalAcknowledgementPost(['requirement_id' => '1']));
        $controller->deactivate();
        assertSameValue('INACTIVE', $repository->findById(new AcknowledgementRequirementId(1))?->status()->value);

        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/activate', institutionalAcknowledgementPost(['requirement_id' => '1']));
        $controller->activate();
        assertSameValue('ACTIVE', $repository->findById(new AcknowledgementRequirementId(1))?->status()->value);
        assertSameValue(1, $repository->hasAcknowledgementsCount);
    });

    $runner->add('E009 administrator cross-period mutation fails closed in Application without save or enumeration', function (): void {
        foreach (['update', 'activate', 'deactivate'] as $method) {
            [$controller, $repository] = institutionalAcknowledgementDeliveryController();
            institutionalAcknowledgementOpen($controller, 9);
            $originalB = $repository->findById(new AcknowledgementRequirementId(2));
            $input = ['requirement_id' => '2'];
            if ($method === 'update') {
                $input += ['title' => 'Tampered B', 'url' => 'tampered/url', 'official_reference' => 'BAD'];
            }
            deliveryRequest('POST', '/institutional-acknowledgements/requirements/' . $method, institutionalAcknowledgementPost($input));
            $response = $controller->{$method}();
            assertSameValue(422, http_response_code());
            deliveryAssertContains('Requirement was not found', $response);
            assertSameValue(false, str_contains($response, 'Period B Requirement'));
            assertSameValue(false, str_contains($response, 'belongs to'));
            assertSameValue(0, $repository->saveCount);
            assertSameValue(0, $repository->hasAcknowledgementsCount);
            assertSameValue('Period B Requirement', $originalB?->title()->value());
            assertSameValue('INACTIVE', $originalB?->status()->value);
        }
    });

    $runner->add('E009 administrator preserves Domain post-use protection while allowing URL updates', function (): void {
        [$controller, $repository] = institutionalAcknowledgementDeliveryController();
        $repository->historicalRequirementIds[1] = true;
        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/update', institutionalAcknowledgementPost([
            'requirement_id' => '1', 'title' => 'Period A Requirement', 'url' => 'new/url', 'official_reference' => 'A-REF',
        ]));
        $controller->update();
        assertSameValue('new/url', $repository->findById(new AcknowledgementRequirementId(1))?->url()->value());

        institutionalAcknowledgementOpen($controller, 9);
        deliveryRequest('POST', '/institutional-acknowledgements/requirements/update', institutionalAcknowledgementPost([
            'requirement_id' => '1', 'title' => 'Changed protected title', 'url' => 'other/url', 'official_reference' => 'A-REF',
        ]));
        $response = $controller->update();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('Requirement could not be changed', $response);
        assertSameValue('Period A Requirement', $repository->findById(new AcknowledgementRequirementId(1))?->title()->value());
        assertSameValue('new/url', $repository->findById(new AcknowledgementRequirementId(1))?->url()->value());
    });

    $runner->add('E009 administrator view escapes content and never creates unsafe arbitrary URL links', function (): void {
        $repository = new ApplicationRequirementRepository([
            applicationRequirement(1, 9, '<script>"& title', 'javascript:alert(1)&x', '<b>"& ref'),
        ]);
        [$controller] = institutionalAcknowledgementDeliveryController($repository);
        $page = institutionalAcknowledgementOpen($controller, 9);
        foreach (['&lt;script&gt;&quot;&amp; title', 'javascript:alert(1)&amp;x', '&lt;b&gt;&quot;&amp; ref'] as $escaped) {
            deliveryAssertContains($escaped, $page);
        }
        assertSameValue(false, str_contains($page, '<script>'));
        assertSameValue(false, str_contains($page, 'href="javascript:'));
    });

    $runner->add('E009 administrator activates and deactivates the operational AcademicPeriod with CSRF and PRG', function (): void {
        [$controller, , $session, , $periodRepository] = institutionalAcknowledgementDeliveryController();
        deliveryRequest('POST', '/institutional-acknowledgements/academic-period/activate', [
            '_csrf_token' => 'delivery-csrf',
            'academic_period_id' => '10',
        ]);
        assertSameValue('', $controller->activateAcademicPeriod());
        assertSameValue(303, http_response_code());
        assertSameValue(10, $periodRepository->findActive()?->id()?->value());
        assertSameValue('INACTIVE', $periodRepository->findById(new CoreAcademicPeriodId(9))?->status()->value);
        assertSameValue('Academic Period activated successfully.', $session->get('_flash_institutional_acknowledgements_success'));

        deliveryRequest('POST', '/institutional-acknowledgements/academic-period/deactivate', [
            '_csrf_token' => 'delivery-csrf',
            'academic_period_id' => '10',
        ]);
        assertSameValue('', $controller->deactivateAcademicPeriod());
        assertSameValue(303, http_response_code());
        assertSameValue(null, $periodRepository->findActive());
    });

    $runner->add('E009 administrator AcademicPeriod lifecycle rejects CSRF malformed and absent targets safely', function (): void {
        foreach ([
            [['_csrf_token' => 'invalid', 'academic_period_id' => '10'], 419],
            [['_csrf_token' => 'delivery-csrf', 'academic_period_id' => 'bad'], 422],
            [['_csrf_token' => 'delivery-csrf', 'academic_period_id' => '999'], 404],
        ] as [$input, $status]) {
            [$controller, , , , $periodRepository] = institutionalAcknowledgementDeliveryController();
            deliveryRequest('POST', '/institutional-acknowledgements/academic-period/activate', [
                '_csrf_token' => $input['_csrf_token'],
                'academic_period_id' => $input['academic_period_id'],
            ]);
            $response = $controller->activateAcademicPeriod();
            assertSameValue($status, http_response_code());
            assertSameValue(9, $periodRepository->findActive()?->id()?->value());
            assertSameValue(false, str_contains($response, 'SQLSTATE'));
        }
    });

    $runner->add('E009 administrator exposes exact routes wiring architecture and White Label scope', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        assertSameValue(7, preg_match_all('/\$router->(?:get|post)\(\s*\'\/institutional-acknowledgements/', $routes));
        assertSameValue(1, preg_match_all('/\$router->get\(\s*\'\/institutional-acknowledgements\'/', $routes));
        assertSameValue(4, preg_match_all('/\$router->post\(\s*\'\/institutional-acknowledgements\/requirements\//', $routes));
        assertSameValue(2, preg_match_all('/\$router->post\(\s*\'\/institutional-acknowledgements\/academic-period\//', $routes));
        deliveryAssertContains('AuthenticationMiddleware::class', $routes);
        deliveryAssertContains('InstitutionalDocumentsAdministrationMiddleware::class', $routes);
        assertSameValue(false, str_contains($routes, '/institutional-acknowledgements/delete'));
        assertSameValue(false, str_contains($routes, '/representative/institutional-acknowledgements'));

        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/InstitutionalDocuments/Http/InstitutionalAcknowledgementController.php');
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Repository', 'ConnectionManager', 'TransactionRunner', 'hasAcknowledgements'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        $view = (string) file_get_contents(dirname(__DIR__) . '/resources/views/institutional-acknowledgements/index.php');
        foreach (['Antares', 'Código de Convivencia', 'Política de Datos', 'Acuerdo de Servicio', 'SELECT ', 'Repository'] as $forbidden) {
            assertSameValue(false, str_contains($view, $forbidden), $forbidden);
        }
        $dashboard = (string) file_get_contents(dirname(__DIR__) . '/resources/views/dashboard/index.php');
        deliveryAssertContains('Manage Institutional Acknowledgements', $dashboard);
        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        foreach (['InstitutionalAcknowledgementController', 'InstitutionalDocumentsAdministrationMiddleware', 'PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider'] as $binding) {
            deliveryAssertContains($binding, $bootstrap);
        }
    });
}

/** @return array{InstitutionalAcknowledgementController, ApplicationRequirementRepository, FakeSessionManager, object, InMemoryAcademicPeriodRepository} */
function institutionalAcknowledgementDeliveryController(?ApplicationRequirementRepository $repository = null): array
{
    $repository ??= new ApplicationRequirementRepository([
        applicationRequirement(1, 9, 'Period A Requirement', 'a/url', 'A-REF'),
        applicationRequirement(2, 10, 'Period B Requirement', 'b/url', null, AcknowledgementRequirementStatus::Inactive),
    ]);
    $periods = [
        new InstitutionalAcknowledgementAcademicPeriodOption(10, 'B', 'Period B', '2026-09-01', '2027-06-30', 'INACTIVE'),
        new InstitutionalAcknowledgementAcademicPeriodOption(9, 'A', 'Period A', '2025-09-01', '2026-06-30', 'ACTIVE'),
    ];
    $provider = new class($periods) implements InstitutionalAcknowledgementAcademicPeriodOptionsProvider {
        public function __construct(public array $periods)
        {
        }

        public function all(): array
        {
            return $this->periods;
        }

        public function findById(int $id): ?InstitutionalAcknowledgementAcademicPeriodOption
        {
            foreach ($this->periods as $period) {
                if ($period->id === $id) {
                    return $period;
                }
            }

            return null;
        }
    };
    $session = new FakeSessionManager();
    $academicPeriods = new InMemoryAcademicPeriodRepository([
        academicPeriodFixture(10, AcademicPeriodStatus::Inactive, '2026-09-01', '2027-06-30'),
        academicPeriodFixture(9, AcademicPeriodStatus::Active, '2025-09-01', '2026-06-30'),
    ]);
    $transactions = new InMemoryCompositeTransactionRunner([$academicPeriods]);

    return [
        new InstitutionalAcknowledgementController(
            new GetAcknowledgementRequirements($repository),
            new CreateAcknowledgementRequirement($repository),
            new UpdateAcknowledgementRequirement($repository),
            new ActivateAcknowledgementRequirement($repository),
            new DeactivateAcknowledgementRequirement($repository),
            new FakeDeliveryCsrf(),
            $session,
            $provider,
            new ActivateAcademicPeriod($academicPeriods, $transactions),
            new DeactivateAcademicPeriod($academicPeriods, $transactions),
        ),
        $repository,
        $session,
        $provider,
        $academicPeriods,
    ];
}

function institutionalAcknowledgementOpen(InstitutionalAcknowledgementController $controller, int $periodId): string
{
    deliveryRequest('GET', '/institutional-acknowledgements?academic_period_id=' . $periodId, [
        'academic_period_id' => (string) $periodId,
    ]);

    return $controller->index();
}

/** @param array<string, mixed> $overrides */
function institutionalAcknowledgementPost(array $overrides = []): array
{
    return array_replace([
        '_csrf_token' => 'delivery-csrf',
        'academic_period_id' => '9',
        'title' => 'Requirement',
        'url' => 'requirement/url',
        'official_reference' => 'REF',
        'status' => 'ACTIVE',
    ], $overrides);
}
