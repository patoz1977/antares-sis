<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use Core\Container\Container;
use Core\Application\TransactionRunner;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use Core\Database\PdoTransactionRunner;
use Core\Foundation\Application;
use Core\Middleware\AuthenticationMiddleware;
use Core\Security\AuthenticatedUserProviderInterface;
use Core\Session\Session;
use Core\Session\SessionInterface;
use App\AcademicCore\Application\ActivateAcademicPeriod;
use App\AcademicCore\Application\DeactivateAcademicPeriod;
use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPeriodRepository;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPlacementReferenceProvider;
use App\Enrollment\Application\RepresentativePortal\GetRepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentPortalAuthorization;
use App\Enrollment\Application\RepresentativePortal\ResolveOrStartRepresentativeEnrollment;
use App\Enrollment\Application\RepresentativePortal\Support\RepresentativeEnrollmentMutationSupport;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeContactInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeEmploymentInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativePersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthorizedStudentPersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentBillingInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentLeaveAloneAuthorization;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentMedicalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentTransportInformation;
use App\Enrollment\Application\Support\EnrollmentDraftInitializer;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Http\RepresentativeEnrollmentController;
use App\Enrollment\Http\RepresentativeEnrollmentInputMapper;
use App\Enrollment\Infrastructure\Persistence\PdoEnrollmentRepository;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Contract\TransactionManager;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthorizedFamilies;
use App\IdentityAccess\Application\GetUserByPersonId;
use App\IdentityAccess\Application\Orchestration\UpdatePersonWithRepresentativeUserSync;
use App\IdentityAccess\Application\RepresentativeFamilyContextSession;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Application\SelectAuthorizedFamily;
use App\IdentityAccess\Domain\UserRepository as IdentityUserRepository;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Http\RepresentativePortalController;
use App\IdentityAccess\Http\RepresentativeUserController;
use App\IdentityAccess\Infrastructure\Logging\ErrorLogSecurityEventLogger;
use App\IdentityAccess\Infrastructure\Persistence\PdoTransactionManager;
use App\IdentityAccess\Infrastructure\Persistence\PdoUserRepository;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\IdentityAccess\Infrastructure\Session\PhpSessionManager;
use App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager;
use App\IdentityAccess\Infrastructure\Time\SystemClock;
use App\Family\Application\AddStudentToFamily;
use App\Family\Application\ActivateFamilyAddress;
use App\Family\Application\ActivateFamilyAuthorizedPickup;
use App\Family\Application\ActivateFamilyEmergencyContact;
use App\Family\Application\AssignAuthorizedPickup;
use App\Family\Application\AssignEmergencyContact;
use App\Family\Application\AssignRepresentativeAddress;
use App\Family\Application\AssignStudentAddress;
use App\Family\Application\CreateFamily;
use App\Family\Application\CreateFamilyAddress;
use App\Family\Application\CreateFamilyAuthorizedPickup;
use App\Family\Application\CreateFamilyEmergencyContact;
use App\Family\Application\DeactivateFamilyAddress;
use App\Family\Application\DeactivateFamilyAuthorizedPickup;
use App\Family\Application\DeactivateFamilyEmergencyContact;
use App\Family\Application\DocumentTypeLookup;
use App\Family\Application\EndAuthorizedPickupAssignment;
use App\Family\Application\EndEmergencyContactAssignment;
use App\Family\Application\EndRepresentativeAddressAssignment;
use App\Family\Application\EndStudentAddressAssignment;
use App\Family\Application\GetFamily;
use App\Family\Application\GetFamilyMembership;
use App\Family\Application\GetFamilyResources;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\RelationshipTypeLookup;
use App\Family\Application\RepresentativeResources\GetRepresentativeFamilyResources;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyAddressService;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyAuthorizedPickupService;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyEmergencyContactService;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyResourceAuthorization;
use App\Family\Application\UpdateFamilyAddress;
use App\Family\Application\UpdateFamilyAuthorizedPickup;
use App\Family\Application\UpdateFamilyEmergencyContact;
use App\Family\Domain\FamilyRepository;
use App\Family\Http\FamilyAdministrationMiddleware;
use App\Family\Http\FamilyController;
use App\Family\Http\FamilyFormOptionsProvider;
use App\Family\Http\FamilyResourceController;
use App\Family\Http\FamilyResourceFormOptionsProvider;
use App\Family\Http\RepresentativeFamilyResourceController;
use App\Family\Infrastructure\Persistence\PdoDocumentTypeLookup;
use App\Family\Infrastructure\Persistence\PdoFamilyFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoFamilyResourceFormOptionsProvider;
use App\Family\Infrastructure\Persistence\PdoFamilyRepository;
use App\Family\Infrastructure\Persistence\PdoRelationshipTypeLookup;
use App\Person\Application\CreatePerson;
use App\Person\Application\GetPerson;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\PersonRepository;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use App\Person\Http\PersonFormOptionsProvider;
use App\Person\Infrastructure\Persistence\PdoPersonFormOptionsProvider;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\GetRepresentative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Infrastructure\Persistence\PdoRepresentativeRepository;
use App\Student\Application\CreateStudent;
use App\Student\Application\GetStudent;
use App\Student\Domain\StudentRepository;
use App\Student\Infrastructure\Persistence\PdoStudentRepository;
use App\InstitutionalDocuments\Application\ActivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\CompleteRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\CreateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\DeactivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\GetAcknowledgementRequirements;
use App\InstitutionalDocuments\Application\GetRepresentativeAcknowledgementState;
use App\InstitutionalDocuments\Application\RepresentativePortal\CompleteAuthenticatedRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState;
use App\InstitutionalDocuments\Application\RepresentativePortal\RequireRepresentativeAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\ResolveRepresentativeAcknowledgementContext;
use App\InstitutionalDocuments\Application\UpdateAcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use App\InstitutionalDocuments\Http\InstitutionalAcknowledgementController;
use App\InstitutionalDocuments\Http\InstitutionalDocumentsAdministrationMiddleware;
use App\InstitutionalDocuments\Http\RepresentativeAcknowledgementController;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoAcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoRepresentativeAcknowledgementCompletionRepository;

use App\Services\AuthenticationService;
use App\Services\AuthenticationServiceInterface;

// Load .env file from project root
$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';

if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // support "export KEY=VAL"
        if (str_starts_with(strtolower($line), 'export ')) {
            $line = preg_replace('/^export\s+/i', '', $line, 1);
        }

        $pos = strpos($line, '=');

        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = substr($line, $pos + 1);

        // remove surrounding whitespace
        $value = trim($value);

        // strip comments for unquoted values
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $parts = preg_split('/\s+#/', $value, 2);
            $value = $parts[0];
        }

        // remove surrounding quotes and unescape
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = str_replace("\\'", "'", substr($value, 1, -1));
        }

        // set into environments
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$config = require $root . '/config/app.php';

$timezone = (string) ($config['timezone'] ?? 'UTC');
$locale = (string) ($config['locale'] ?? 'en_US.UTF-8');

date_default_timezone_set($timezone);

if (!setlocale(LC_ALL, $locale)) {
    setlocale(LC_ALL, 'C');
}

$databaseConfigValues = require $root . '/config/database.php';

$databaseConfigValues['username'] = (string) ($databaseConfigValues['username'] ?? '');
$databaseConfigValues['password'] = (string) ($databaseConfigValues['password'] ?? '');
$databaseConfigValues['database'] = (string) ($databaseConfigValues['database'] ?? '');
$databaseConfigValues['host'] = (string) ($databaseConfigValues['host'] ?? '');
$databaseConfigValues['charset'] = (string) ($databaseConfigValues['charset'] ?? 'utf8mb4');

$databaseConfig = new DatabaseConfig($databaseConfigValues);

$container = new Container();
$container->instance(DatabaseConfig::class, $databaseConfig);
$container->singleton(ConnectionFactory::class, ConnectionFactory::class);
$container->singleton(ConnectionManager::class, ConnectionManager::class);
$container->singleton(Session::class, Session::class);
$container->singleton(SessionInterface::class, Session::class);
$container->singleton(AuthenticationService::class, AuthenticationService::class);
$container->singleton(AuthenticationServiceInterface::class, AuthenticationService::class);
$container->singleton(SessionManager::class, PhpSessionManager::class);
$container->singleton(CsrfTokenManager::class, SessionCsrfTokenManager::class);
$container->singleton(Clock::class, SystemClock::class);
$container->singleton(PasswordHasher::class, NativePasswordHasher::class);
$container->singleton(SecurityEventLogger::class, ErrorLogSecurityEventLogger::class);
$container->singleton(TransactionManager::class, PdoTransactionManager::class);
$container->singleton(IdentityUserRepository::class, PdoUserRepository::class);
$container->singleton(RepresentativePasswordPolicy::class, RepresentativePasswordPolicy::class);
$maximumFailedAttempts = filter_var(
    $config['auth_max_failed_attempts'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$lockoutDurationSeconds = filter_var(
    $config['auth_lockout_duration_seconds'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$container->instance(
    AuthenticationPolicy::class,
    new AuthenticationPolicy(
        is_int($maximumFailedAttempts) ? $maximumFailedAttempts : 0,
        is_int($lockoutDurationSeconds) ? $lockoutDurationSeconds : 0,
    )
);
$container->singleton(AuthenticatedUserProviderInterface::class, AuthenticationService::class);
$container->singleton(AuthenticationController::class, AuthenticationController::class);
$container->singleton(RepresentativePortalController::class, RepresentativePortalController::class);
$container->singleton(AuthenticationMiddleware::class, AuthenticationMiddleware::class);
$container->singleton(PersonRepository::class, PdoPersonRepository::class);
$container->singleton(CreatePerson::class, CreatePerson::class);
$container->singleton(GetPerson::class, GetPerson::class);
$container->singleton(UpdatePerson::class, UpdatePerson::class);
$container->singleton(GetUserByPersonId::class, GetUserByPersonId::class);
$container->singleton(CreateRepresentativeUser::class, CreateRepresentativeUser::class);
$container->singleton(GetAuthenticatedRepresentative::class, GetAuthenticatedRepresentative::class);
$container->singleton(GetAuthorizedFamilies::class, GetAuthorizedFamilies::class);
$container->singleton(
    RepresentativeFamilyContextSession::class,
    RepresentativeFamilyContextSession::class,
);
$container->singleton(ResolveFamilyContext::class, ResolveFamilyContext::class);
$container->singleton(SelectAuthorizedFamily::class, SelectAuthorizedFamily::class);
$container->singleton(
    ChangeRepresentativeUserPassword::class,
    ChangeRepresentativeUserPassword::class,
);
$container->singleton(
    UpdatePersonWithRepresentativeUserSync::class,
    UpdatePersonWithRepresentativeUserSync::class,
);
$container->singleton(PersonFormOptionsProvider::class, PdoPersonFormOptionsProvider::class);
$container->singleton(PersonController::class, PersonController::class);
$container->singleton(PersonAdministrationMiddleware::class, PersonAdministrationMiddleware::class);
$container->singleton(TransactionRunner::class, PdoTransactionRunner::class);
$container->singleton(AcademicPeriodRepository::class, PdoAcademicPeriodRepository::class);
$container->singleton(AcademicPlacementReferenceProvider::class, PdoAcademicPlacementReferenceProvider::class);
$container->singleton(GetActiveAcademicPeriod::class, GetActiveAcademicPeriod::class);
$container->singleton(ActivateAcademicPeriod::class, ActivateAcademicPeriod::class);
$container->singleton(DeactivateAcademicPeriod::class, DeactivateAcademicPeriod::class);
$container->singleton(RepresentativeRepository::class, PdoRepresentativeRepository::class);
$container->singleton(StudentRepository::class, PdoStudentRepository::class);
$container->singleton(EnrollmentRepository::class, PdoEnrollmentRepository::class);
$container->singleton(FamilyRepository::class, PdoFamilyRepository::class);
$container->singleton(RelationshipTypeLookup::class, PdoRelationshipTypeLookup::class);
$container->singleton(DocumentTypeLookup::class, PdoDocumentTypeLookup::class);
$container->singleton(FamilyFormOptionsProvider::class, PdoFamilyFormOptionsProvider::class);
$container->singleton(
    FamilyResourceFormOptionsProvider::class,
    PdoFamilyResourceFormOptionsProvider::class,
);
$container->singleton(CreateRepresentative::class, CreateRepresentative::class);
$container->singleton(GetRepresentative::class, GetRepresentative::class);
$container->singleton(CreateStudent::class, CreateStudent::class);
$container->singleton(GetStudent::class, GetStudent::class);
$container->singleton(CreateFamily::class, CreateFamily::class);
$container->singleton(AddStudentToFamily::class, AddStudentToFamily::class);
$container->singleton(GetFamily::class, GetFamily::class);
$container->singleton(GetFamilyMembership::class, GetFamilyMembership::class);
$container->singleton(GetFamilyResources::class, GetFamilyResources::class);
$container->singleton(CreateFamilyAddress::class, CreateFamilyAddress::class);
$container->singleton(UpdateFamilyAddress::class, UpdateFamilyAddress::class);
$container->singleton(ActivateFamilyAddress::class, ActivateFamilyAddress::class);
$container->singleton(DeactivateFamilyAddress::class, DeactivateFamilyAddress::class);
$container->singleton(AssignRepresentativeAddress::class, AssignRepresentativeAddress::class);
$container->singleton(
    EndRepresentativeAddressAssignment::class,
    EndRepresentativeAddressAssignment::class,
);
$container->singleton(AssignStudentAddress::class, AssignStudentAddress::class);
$container->singleton(EndStudentAddressAssignment::class, EndStudentAddressAssignment::class);
$container->singleton(CreateFamilyEmergencyContact::class, CreateFamilyEmergencyContact::class);
$container->singleton(UpdateFamilyEmergencyContact::class, UpdateFamilyEmergencyContact::class);
$container->singleton(ActivateFamilyEmergencyContact::class, ActivateFamilyEmergencyContact::class);
$container->singleton(
    DeactivateFamilyEmergencyContact::class,
    DeactivateFamilyEmergencyContact::class,
);
$container->singleton(AssignEmergencyContact::class, AssignEmergencyContact::class);
$container->singleton(EndEmergencyContactAssignment::class, EndEmergencyContactAssignment::class);
$container->singleton(CreateFamilyAuthorizedPickup::class, CreateFamilyAuthorizedPickup::class);
$container->singleton(UpdateFamilyAuthorizedPickup::class, UpdateFamilyAuthorizedPickup::class);
$container->singleton(ActivateFamilyAuthorizedPickup::class, ActivateFamilyAuthorizedPickup::class);
$container->singleton(
    DeactivateFamilyAuthorizedPickup::class,
    DeactivateFamilyAuthorizedPickup::class,
);
$container->singleton(AssignAuthorizedPickup::class, AssignAuthorizedPickup::class);
$container->singleton(EndAuthorizedPickupAssignment::class, EndAuthorizedPickupAssignment::class);
$container->singleton(
    RepresentativeFamilyResourceAuthorization::class,
    RepresentativeFamilyResourceAuthorization::class,
);
$container->singleton(GetRepresentativeFamilyResources::class, GetRepresentativeFamilyResources::class);
$container->singleton(RepresentativeFamilyAddressService::class, RepresentativeFamilyAddressService::class);
$container->singleton(
    RepresentativeFamilyEmergencyContactService::class,
    RepresentativeFamilyEmergencyContactService::class,
);
$container->singleton(
    RepresentativeFamilyAuthorizedPickupService::class,
    RepresentativeFamilyAuthorizedPickupService::class,
);
$container->singleton(CreateRepresentativeFamily::class, CreateRepresentativeFamily::class);
$container->singleton(CreateStudentInFamily::class, CreateStudentInFamily::class);
$container->singleton(FamilyController::class, FamilyController::class);
$container->singleton(FamilyResourceController::class, FamilyResourceController::class);
$container->singleton(
    RepresentativeFamilyResourceController::class,
    RepresentativeFamilyResourceController::class,
);
$container->singleton(FamilyAdministrationMiddleware::class, FamilyAdministrationMiddleware::class);
$container->singleton(RepresentativeUserController::class, RepresentativeUserController::class);
$container->singleton(
    AcknowledgementRequirementRepository::class,
    PdoAcknowledgementRequirementRepository::class,
);
$container->singleton(
    RepresentativeAcknowledgementCompletionRepository::class,
    PdoRepresentativeAcknowledgementCompletionRepository::class,
);
$container->singleton(GetAcknowledgementRequirements::class, GetAcknowledgementRequirements::class);
$container->singleton(CreateAcknowledgementRequirement::class, CreateAcknowledgementRequirement::class);
$container->singleton(UpdateAcknowledgementRequirement::class, UpdateAcknowledgementRequirement::class);
$container->singleton(ActivateAcknowledgementRequirement::class, ActivateAcknowledgementRequirement::class);
$container->singleton(DeactivateAcknowledgementRequirement::class, DeactivateAcknowledgementRequirement::class);
$container->singleton(GetRepresentativeAcknowledgementState::class, GetRepresentativeAcknowledgementState::class);
$container->singleton(CompleteRepresentativeAcknowledgements::class, CompleteRepresentativeAcknowledgements::class);
$container->singleton(
    InstitutionalAcknowledgementSatisfaction::class,
    CheckInstitutionalAcknowledgementSatisfaction::class,
);
$container->singleton(
    ResolveRepresentativeAcknowledgementContext::class,
    ResolveRepresentativeAcknowledgementContext::class,
);
$container->singleton(
    GetRepresentativeAcknowledgementPortalState::class,
    GetRepresentativeAcknowledgementPortalState::class,
);
$container->singleton(
    CompleteAuthenticatedRepresentativeAcknowledgements::class,
    CompleteAuthenticatedRepresentativeAcknowledgements::class,
);
$container->singleton(
    RequireRepresentativeAcknowledgementSatisfaction::class,
    RequireRepresentativeAcknowledgementSatisfaction::class,
);
$container->singleton(
    InstitutionalAcknowledgementAcademicPeriodOptionsProvider::class,
    PdoInstitutionalAcknowledgementAcademicPeriodOptionsProvider::class,
);
$container->singleton(
    InstitutionalDocumentsAdministrationMiddleware::class,
    InstitutionalDocumentsAdministrationMiddleware::class,
);
$container->singleton(
    InstitutionalAcknowledgementController::class,
    InstitutionalAcknowledgementController::class,
);
$container->singleton(
    RepresentativeAcknowledgementController::class,
    RepresentativeAcknowledgementController::class,
);
$container->singleton(EnrollmentDraftInitializer::class, EnrollmentDraftInitializer::class);
$container->singleton(
    RepresentativeEnrollmentPortalAuthorization::class,
    RepresentativeEnrollmentPortalAuthorization::class,
);
$container->singleton(
    RepresentativeEnrollmentMutationSupport::class,
    RepresentativeEnrollmentMutationSupport::class,
);
$container->singleton(
    GetRepresentativeEnrollmentPortalState::class,
    GetRepresentativeEnrollmentPortalState::class,
);
$container->singleton(
    ResolveOrStartRepresentativeEnrollment::class,
    ResolveOrStartRepresentativeEnrollment::class,
);
$container->singleton(
    UpdateAuthenticatedRepresentativePersonalInformation::class,
    UpdateAuthenticatedRepresentativePersonalInformation::class,
);
$container->singleton(
    UpdateAuthenticatedRepresentativeContactInformation::class,
    UpdateAuthenticatedRepresentativeContactInformation::class,
);
$container->singleton(
    UpdateAuthenticatedRepresentativeEmploymentInformation::class,
    UpdateAuthenticatedRepresentativeEmploymentInformation::class,
);
$container->singleton(
    UpdateAuthorizedStudentPersonalInformation::class,
    UpdateAuthorizedStudentPersonalInformation::class,
);
$container->singleton(
    UpdateRepresentativeEnrollmentBillingInformation::class,
    UpdateRepresentativeEnrollmentBillingInformation::class,
);
$container->singleton(
    UpdateRepresentativeEnrollmentMedicalInformation::class,
    UpdateRepresentativeEnrollmentMedicalInformation::class,
);
$container->singleton(
    UpdateRepresentativeEnrollmentTransportInformation::class,
    UpdateRepresentativeEnrollmentTransportInformation::class,
);
$container->singleton(
    UpdateRepresentativeEnrollmentLeaveAloneAuthorization::class,
    UpdateRepresentativeEnrollmentLeaveAloneAuthorization::class,
);
$container->singleton(RepresentativeEnrollmentInputMapper::class, RepresentativeEnrollmentInputMapper::class);
$container->singleton(RepresentativeEnrollmentController::class, RepresentativeEnrollmentController::class);

$app = new Application($config, $container);

$app->kernel()->setMiddlewareResolver(
    static fn (string $middleware): object => $container->make($middleware)
);

return $app;
