<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\GetUserByPersonId;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Http\RepresentativeUserController;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Application\GetPerson;
use App\Representative\Application\GetRepresentative;
use DateTimeImmutable;
use Tests\Support\TestRunner;

function registerRepresentativeUserDeliveryTests(TestRunner $runner): void
{
    $runner->add('Representative User routes use exact admin middleware and expose only three operations', function (): void {
        $routes = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'),
        );
        foreach ([
            "get(\n    '/representative-users/manage'",
            "post(\n    '/representative-users/create'",
            "post(\n    '/representative-users/password'",
        ] as $route) {
            assertSameValue(true, str_contains($routes, $route));
        }

        assertSameValue(3, substr_count($routes, '$representativeUserMiddleware,'));
        assertSameValue(true, str_contains($routes, 'AuthenticationMiddleware::class'));
        assertSameValue(true, str_contains($routes, 'PersonAdministrationMiddleware::class'));
        foreach (['delete', 'unlock', 'forgot', 'export', 'list'] as $excluded) {
            assertSameValue(false, str_contains($routes, '/representative-users/' . $excluded));
        }
    });

    $runner->add('Representative User manage form derives read-only username and keeps passwords empty', function (): void {
        [$controller] = representativeUserDeliveryController();
        deliveryRequest('GET', '/representative-users/manage?representative_id=200', [
            'representative_id' => '200',
        ]);

        $html = $controller->showManage();

        deliveryAssertContains('Manage Representative User', $html);
        deliveryAssertContains('Representative-100', $html);
        deliveryAssertContains('action="/representative-users/create"', $html);
        deliveryAssertContains('name="password"', $html);
        deliveryAssertContains('value=""', $html);
        assertSameValue(false, str_contains($html, 'password_hash'));
        assertSameValue(false, str_contains($html, 'PasswordHash'));
    });

    $runner->add('Representative User creation is usable and never renders the submitted password', function (): void {
        [$controller, , , $users] = representativeUserDeliveryController();
        representativeUserOpenManage($controller);
        deliveryRequest('POST', '/representative-users/create', [
            '_csrf_token' => 'delivery-csrf',
            'representative_id' => '200',
            'password' => 'secret-five',
            'password_confirmation' => 'secret-five',
            'status' => 'ACTIVE',
        ]);

        $response = $controller->create();
        assertSameValue('', $response);
        assertSameValue(303, http_response_code());
        $stored = $users->findByPersonId(new UserPersonId(100));
        assertSameValue(true, ($stored?->id()?->value() ?? 0) > 0);
        assertSameValue('representative-100', $stored?->loginIdentifier()->value());
        assertSameValue(false, $stored?->passwordHash()->value() === 'secret-five');

        deliveryRequest('GET', '/representative-users/manage?representative_id=200', [
            'representative_id' => '200',
        ]);
        $html = $controller->showManage();
        deliveryAssertContains('Representative User created successfully.', $html);
        deliveryAssertContains('action="/representative-users/password"', $html);
        assertSameValue(false, str_contains($html, 'secret-five'));
        assertSameValue(false, str_contains($html, $stored?->passwordHash()->value() ?? 'not-present'));
    });

    $runner->add('Representative User delivery rejects password mismatch invalid policy and duplicate safely', function (): void {
        [$controller, , , $users] = representativeUserDeliveryController();
        representativeUserOpenManage($controller);
        $input = [
            '_csrf_token' => 'delivery-csrf',
            'representative_id' => '200',
            'password' => 'not-matching-secret',
            'password_confirmation' => 'different-secret',
            'status' => 'ACTIVE',
        ];
        deliveryRequest('POST', '/representative-users/create', $input);
        $mismatch = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('does not match', $mismatch);
        assertSameValue(false, str_contains($mismatch, 'not-matching-secret'));
        assertSameValue(0, $users->saveCalls());

        representativeUserOpenManage($controller);
        $input['password'] = '1234';
        $input['password_confirmation'] = '1234';
        deliveryRequest('POST', '/representative-users/create', $input);
        $short = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('at least five', $short);
        assertSameValue(false, str_contains($short, '1234'));

        $users->seed(representativeUserUser(77, 100, 'representative-100'));
        representativeUserOpenManage($controller);
        $input['password'] = 'valid-password';
        $input['password_confirmation'] = 'valid-password';
        deliveryRequest('POST', '/representative-users/create', $input);
        $duplicate = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('already has a User', $duplicate);
        assertSameValue(false, str_contains($duplicate, 'valid-password'));
    });

    $runner->add('Representative User delivery rejects CSRF tampering and expired trusted identity', function (): void {
        [$controller, , , $users] = representativeUserDeliveryController();
        representativeUserOpenManage($controller);
        deliveryRequest('POST', '/representative-users/create', [
            '_csrf_token' => 'invalid',
            'representative_id' => '200',
            'password' => 'never-stored',
            'password_confirmation' => 'never-stored',
            'status' => 'ACTIVE',
        ]);
        $csrf = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('form expired', $csrf);
        assertSameValue(false, str_contains($csrf, 'never-stored'));
        assertSameValue(0, $users->saveCalls());

        representativeUserOpenManage($controller);
        deliveryRequest('POST', '/representative-users/create', [
            '_csrf_token' => 'delivery-csrf',
            'representative_id' => '201',
            'password' => 'tampered-secret',
            'password_confirmation' => 'tampered-secret',
            'status' => 'ACTIVE',
        ]);
        $tampered = $controller->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('identity cannot be changed', $tampered);
        assertSameValue(false, str_contains($tampered, 'tampered-secret'));

        [$expiredController] = representativeUserDeliveryController();
        deliveryRequest('POST', '/representative-users/create', [
            '_csrf_token' => 'delivery-csrf',
            'representative_id' => '200',
            'password' => 'expired-secret',
            'password_confirmation' => 'expired-secret',
            'status' => 'ACTIVE',
        ]);
        $expired = $expiredController->create();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('session expired', $expired);
        assertSameValue(false, str_contains($expired, 'expired-secret'));
    });

    $runner->add('Administrative password change preserves authentication state and safe rendering', function (): void {
        [$controller, , , $users] = representativeUserDeliveryController();
        $lockedAt = new DateTimeImmutable('2026-08-10 10:00:00+00:00');
        $lastAccessAt = new DateTimeImmutable('2026-08-09 10:00:00+00:00');
        $users->seed(new User(
            new UserId(88),
            new UserPersonId(100),
            new LoginIdentifier('representative-100'),
            new PasswordHash((new NativePasswordHasher())->hash('old-secret')),
            UserStatus::Disabled,
            5,
            $lockedAt,
            $lastAccessAt,
        ));
        representativeUserOpenManage($controller);
        deliveryRequest('POST', '/representative-users/password', [
            '_csrf_token' => 'delivery-csrf',
            'representative_id' => '200',
            'new_password' => 'new-secret',
            'new_password_confirmation' => 'new-secret',
        ]);

        assertSameValue('', $controller->changePassword());
        assertSameValue(303, http_response_code());
        $stored = $users->findByPersonId(new UserPersonId(100));
        assertSameValue(88, $stored?->id()?->value());
        assertSameValue(UserStatus::Disabled, $stored?->status());
        assertSameValue(5, $stored?->failedLoginAttempts());
        assertSameValue($lockedAt->getTimestamp(), $stored?->lockedAt()?->getTimestamp());
        assertSameValue($lastAccessAt->getTimestamp(), $stored?->lastAccessAt()?->getTimestamp());
        assertSameValue(true, (new NativePasswordHasher())->verify(
            'new-secret',
            $stored?->passwordHash()->value() ?? '',
        ));

        deliveryRequest('GET', '/representative-users/manage?representative_id=200', [
            'representative_id' => '200',
        ]);
        $html = $controller->showManage();
        deliveryAssertContains('password changed successfully', $html);
        assertSameValue(false, str_contains($html, 'new-secret'));
        assertSameValue(false, str_contains($html, 'old-secret'));
    });

    $runner->add('Representative User delivery returns safe not found and escaped White Label output', function (): void {
        [$controller] = representativeUserDeliveryController();
        deliveryRequest('GET', '/representative-users/manage?representative_id=999', [
            'representative_id' => '999',
        ]);
        $notFound = $controller->showManage();
        assertSameValue(404, http_response_code());
        deliveryAssertContains('Representative not found', $notFound);
        assertSameValue(false, str_contains($notFound, 'SQL'));

        $controllerSource = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/RepresentativeUserController.php'
        );
        assertSameValue(false, str_contains($controllerSource, 'PDO'));
        assertSameValue(false, str_contains($controllerSource, 'password_hash'));

        $viewSource = '';
        foreach (glob(dirname(__DIR__) . '/resources/views/representative-users/*.php') ?: [] as $view) {
            $viewSource .= (string) file_get_contents($view);
        }
        assertSameValue(0, preg_match('/antares|ueant/i', $viewSource));
        assertSameValue(false, str_contains($viewSource, 'PasswordHash'));
    });
}

/** @return array{RepresentativeUserController, InMemoryPersonApplicationRepository, InMemoryRepresentativeApplicationRepository, InMemoryRepresentativeUserRepository, FakeSessionManager} */
function representativeUserDeliveryController(): array
{
    $persons = new InMemoryPersonApplicationRepository(representativeUserToday());
    $persons->seed(representativeUserPerson(100, new \App\Person\Domain\ValueObject\Identification(
        10,
        'Representative-100',
    )));
    $representatives = new InMemoryRepresentativeApplicationRepository();
    $representatives->seed(representativeUserRepresentative(200, 100));
    $users = new InMemoryRepresentativeUserRepository();
    $session = new FakeSessionManager();
    $hasher = new NativePasswordHasher();
    $policy = new RepresentativePasswordPolicy();

    return [
        new RepresentativeUserController(
            new GetRepresentative($representatives),
            new GetPerson($persons),
            new GetUserByPersonId($users),
            new CreateRepresentativeUser($representatives, $persons, $users, $hasher, $policy),
            new ChangeRepresentativeUserPassword($representatives, $users, $hasher, $policy),
            new FakeDeliveryCsrf(),
            $session,
        ),
        $persons,
        $representatives,
        $users,
        $session,
    ];
}

function representativeUserOpenManage(RepresentativeUserController $controller): string
{
    deliveryRequest('GET', '/representative-users/manage?representative_id=200', [
        'representative_id' => '200',
    ]);

    return $controller->showManage();
}
