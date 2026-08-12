<?php

declare(strict_types=1);

use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Http\RepresentativePortalController;
use App\IdentityAccess\Http\RepresentativeUserController;
use App\Family\Http\FamilyAdministrationMiddleware;
use App\Family\Http\FamilyController;
use App\Family\Http\FamilyResourceController;
use App\Family\Http\RepresentativeFamilyResourceController;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use Core\Middleware\AuthenticationMiddleware;
use Core\Foundation\Application;
use Core\Routing\Router;

/** @var Router $router */
/** @var Application $app */
$authenticationController = $app->container()->make(AuthenticationController::class);
$representativePortalController = $app->container()->make(RepresentativePortalController::class);
$representativeUserController = $app->container()->make(RepresentativeUserController::class);
$personController = $app->container()->make(PersonController::class);
$familyController = $app->container()->make(FamilyController::class);
$familyResourceController = $app->container()->make(FamilyResourceController::class);
$representativeFamilyResourceController = $app->container()->make(
    RepresentativeFamilyResourceController::class,
);
$personMiddleware = [
    AuthenticationMiddleware::class,
    PersonAdministrationMiddleware::class,
];
$familyMiddleware = [
    AuthenticationMiddleware::class,
    FamilyAdministrationMiddleware::class,
];
$representativeUserMiddleware = [
    AuthenticationMiddleware::class,
    PersonAdministrationMiddleware::class,
];

$router->get('/login', [$authenticationController, 'showLogin']);
$router->get('/forgot-password', [$authenticationController, 'showForgotPassword']);
$router->post('/login', [$authenticationController, 'login']);
$router->post('/logout', [$authenticationController, 'logout']);
$router->get('/', [$authenticationController, 'dashboard'], AuthenticationMiddleware::class);
$router->get(
    '/representative',
    [$representativePortalController, 'index'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/family',
    [$representativePortalController, 'selectFamily'],
    AuthenticationMiddleware::class,
);
$router->get(
    '/representative/resources',
    [$representativeFamilyResourceController, 'index'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/addresses/create',
    [$representativeFamilyResourceController, 'createAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/addresses/update',
    [$representativeFamilyResourceController, 'updateAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/addresses/activate',
    [$representativeFamilyResourceController, 'activateAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/addresses/deactivate',
    [$representativeFamilyResourceController, 'deactivateAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/address',
    [$representativeFamilyResourceController, 'assignRepresentativeAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/address/end',
    [$representativeFamilyResourceController, 'endRepresentativeAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/students/address',
    [$representativeFamilyResourceController, 'assignStudentAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/students/address/end',
    [$representativeFamilyResourceController, 'endStudentAddress'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/create',
    [$representativeFamilyResourceController, 'createEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/update',
    [$representativeFamilyResourceController, 'updateEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/activate',
    [$representativeFamilyResourceController, 'activateEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/deactivate',
    [$representativeFamilyResourceController, 'deactivateEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/assign',
    [$representativeFamilyResourceController, 'assignEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/emergency-contacts/end',
    [$representativeFamilyResourceController, 'endEmergencyContact'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/create',
    [$representativeFamilyResourceController, 'createAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/update',
    [$representativeFamilyResourceController, 'updateAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/activate',
    [$representativeFamilyResourceController, 'activateAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/deactivate',
    [$representativeFamilyResourceController, 'deactivateAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/assign',
    [$representativeFamilyResourceController, 'assignAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->post(
    '/representative/resources/authorized-pickups/end',
    [$representativeFamilyResourceController, 'endAuthorizedPickup'],
    AuthenticationMiddleware::class,
);
$router->get('/persons', [$personController, 'index'], $personMiddleware);
$router->get('/persons/create', [$personController, 'showCreate'], $personMiddleware);
$router->post('/persons/create', [$personController, 'create'], $personMiddleware);
$router->get('/persons/show', [$personController, 'show'], $personMiddleware);
$router->get('/persons/edit', [$personController, 'showEdit'], $personMiddleware);
$router->post('/persons/update', [$personController, 'update'], $personMiddleware);
$router->get('/families', [$familyController, 'index'], $familyMiddleware);
$router->get('/families/create', [$familyController, 'showCreateRepresentativeFamily'], $familyMiddleware);
$router->post('/families/create', [$familyController, 'createRepresentativeFamily'], $familyMiddleware);
$router->get('/families/show', [$familyController, 'show'], $familyMiddleware);
$router->get('/families/students/create', [$familyController, 'showCreateStudent'], $familyMiddleware);
$router->post('/families/students/create', [$familyController, 'createStudent'], $familyMiddleware);
$router->get('/families/resources', [$familyResourceController, 'index'], $familyMiddleware);
$router->post('/families/resources/addresses/create', [$familyResourceController, 'createAddress'], $familyMiddleware);
$router->post('/families/resources/addresses/update', [$familyResourceController, 'updateAddress'], $familyMiddleware);
$router->post('/families/resources/addresses/activate', [$familyResourceController, 'activateAddress'], $familyMiddleware);
$router->post('/families/resources/addresses/deactivate', [$familyResourceController, 'deactivateAddress'], $familyMiddleware);
$router->post('/families/resources/representatives/address', [$familyResourceController, 'assignRepresentativeAddress'], $familyMiddleware);
$router->post('/families/resources/representatives/address/end', [$familyResourceController, 'endRepresentativeAddress'], $familyMiddleware);
$router->post('/families/resources/students/address', [$familyResourceController, 'assignStudentAddress'], $familyMiddleware);
$router->post('/families/resources/students/address/end', [$familyResourceController, 'endStudentAddress'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/create', [$familyResourceController, 'createEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/update', [$familyResourceController, 'updateEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/activate', [$familyResourceController, 'activateEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/deactivate', [$familyResourceController, 'deactivateEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/assign', [$familyResourceController, 'assignEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/emergency-contacts/end', [$familyResourceController, 'endEmergencyContact'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/create', [$familyResourceController, 'createAuthorizedPickup'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/update', [$familyResourceController, 'updateAuthorizedPickup'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/activate', [$familyResourceController, 'activateAuthorizedPickup'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/deactivate', [$familyResourceController, 'deactivateAuthorizedPickup'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/assign', [$familyResourceController, 'assignAuthorizedPickup'], $familyMiddleware);
$router->post('/families/resources/authorized-pickups/end', [$familyResourceController, 'endAuthorizedPickup'], $familyMiddleware);
$router->get(
    '/representative-users/manage',
    [$representativeUserController, 'showManage'],
    $representativeUserMiddleware,
);
$router->post(
    '/representative-users/create',
    [$representativeUserController, 'create'],
    $representativeUserMiddleware,
);
$router->post(
    '/representative-users/password',
    [$representativeUserController, 'changePassword'],
    $representativeUserMiddleware,
);
