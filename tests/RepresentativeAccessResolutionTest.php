<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use Core\Container\Container;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerRepresentativeAccessResolutionTests(TestRunner $runner): void
{
    $runner->add('Representative access returns absence without an authenticated User', function (): void {
        [$useCase, , $representatives] = representativeAccessFixture(withUser: false);

        assertSameValue(null, $useCase->handle());
        assertSameValue([], $representatives->requestedPersonIds);
        assertSameValue(0, $representatives->saveCalls);
    });

    $runner->add('Authenticated non-Representative User resolves to safe absence', function (): void {
        [$useCase, $users, $representatives] = representativeAccessFixture(
            withRepresentative: false,
            loginIdentifier: 'admin',
        );

        assertSameValue(null, $useCase->handle());
        assertSameValue([22], $representatives->requestedPersonIds);
        assertSameValue(0, $users->saveCalls());
        assertSameValue(0, $representatives->saveCalls);
    });

    $runner->add('Authenticated Representative resolves exact server-derived identity', function (): void {
        [$useCase, $users, $representatives] = representativeAccessFixture();

        $result = $useCase->handle();

        assertSameValue(true, $result instanceof AuthenticatedRepresentative);
        assertSameValue(11, $result?->userId);
        assertSameValue(22, $result?->personId);
        assertSameValue(33, $result?->representativeId);
        assertSameValue('representative-22', $result?->loginIdentifier);
        assertSameValue([22], $representatives->requestedPersonIds);
        assertSameValue(0, $users->saveCalls());
        assertSameValue(0, $representatives->saveCalls);
    });

    $runner->add('Inactive Representative status does not invent an access denial', function (): void {
        [$useCase] = representativeAccessFixture(RepresentativeStatus::Inactive);

        assertSameValue(33, $useCase->handle()?->representativeId);
    });

    $runner->add('Representative access rejects an unpersisted or mismatched role identity', function (): void {
        [$unpersisted] = representativeAccessFixture(
            representative: new Representative(
                null,
                new PersonId(22),
                null,
                RepresentativeStatus::Active,
            ),
        );
        [$mismatched] = representativeAccessFixture(
            representative: new Representative(
                new RepresentativeId(33),
                new PersonId(99),
                null,
                RepresentativeStatus::Active,
            ),
        );

        assertThrows($unpersisted->handle(...), InvalidPersistedRepresentativeResult::class);
        assertThrows($mismatched->handle(...), InvalidPersistedRepresentativeResult::class);
    });

    $runner->add('Disabled or deleted session User does not resolve Representative access', function (): void {
        [$disabled, , $disabledRepresentatives] = representativeAccessFixture(
            userStatus: UserStatus::Disabled,
        );
        [$deleted, , $deletedRepresentatives] = representativeAccessFixture(withUser: false);
        representativeAccessSession($deleted)->userId = 11;

        assertSameValue(null, $disabled->handle());
        assertSameValue(null, $deleted->handle());
        assertSameValue([], $disabledRepresentatives->requestedPersonIds);
        assertSameValue([], $deletedRepresentatives->requestedPersonIds);
    });

    $runner->add('Representative access has no external identity input or unsafe output', function (): void {
        $useCase = new ReflectionClass(GetAuthenticatedRepresentative::class);
        $method = $useCase->getMethod('handle');
        $output = new ReflectionClass(AuthenticatedRepresentative::class);

        assertSameValue(0, $method->getNumberOfParameters());
        assertSameValue(
            ['userId', 'personId', 'representativeId', 'loginIdentifier'],
            array_map(static fn ($property): string => $property->getName(), $output->getProperties()),
        );
    });

    $runner->add('Representative access Application remains isolated and read-only', function (): void {
        $source = representativeAccessSource(GetAuthenticatedRepresentative::class);
        foreach ([
            'Infrastructure', 'PDO', 'Http', 'Family', 'Student', 'Transaction',
            'SessionManager', 'UserRepository', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }

        $representativeDomain = representativeAccessDirectorySource('app/Representative/Domain');
        $identityDomain = representativeAccessDirectorySource('app/IdentityAccess/Domain');
        assertSameValue(false, str_contains($representativeDomain, 'IdentityAccess'));
        assertSameValue(false, str_contains($identityDomain, 'Representative'));
    });

    $runner->add('Representative access wiring reuses existing authenticated User and repository', function (): void {
        [$useCase, , $representatives] = representativeAccessFixture();
        $reflection = new ReflectionClass($useCase);
        $constructorTypes = array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $reflection->getConstructor()?->getParameters() ?? [],
        );
        assertSameValue([GetAuthenticatedUser::class, RepresentativeRepository::class], $constructorTypes);

        $container = new Container();
        $getAuthenticatedUser = representativeAccessGetAuthenticatedUser($useCase);
        $container->instance(GetAuthenticatedUser::class, $getAuthenticatedUser);
        $container->instance(RepresentativeRepository::class, $representatives);
        $container->singleton(GetAuthenticatedRepresentative::class, GetAuthenticatedRepresentative::class);
        assertSameValue(33, $container->make(GetAuthenticatedRepresentative::class)->handle()?->representativeId);

        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        assertSameValue(true, str_contains(
            $bootstrap,
            '$container->singleton(GetAuthenticatedRepresentative::class, GetAuthenticatedRepresentative::class);',
        ));
    });

    $runner->add('Representative access remains independent of Family context and Portal delivery', function (): void {
        $source = representativeAccessSource(GetAuthenticatedRepresentative::class);

        assertSameValue(false, str_contains($source, 'Family'));
        assertSameValue(false, str_contains($source, 'SessionManager'));
        assertSameValue(false, str_contains($source, 'Http'));
    });
}

/** @return array{GetAuthenticatedRepresentative, InMemoryRepresentativeUserRepository, RepresentativeAccessResolutionTest} */
function representativeAccessFixture(
    RepresentativeStatus $representativeStatus = RepresentativeStatus::Active,
    UserStatus $userStatus = UserStatus::Active,
    bool $withUser = true,
    bool $withRepresentative = true,
    ?Representative $representative = null,
    string $loginIdentifier = 'Representative-22',
): array {
    $session = new FakeSessionManager();
    $users = new InMemoryRepresentativeUserRepository();
    if ($withUser) {
        $users->seed(new User(
            new UserId(11),
            new UserPersonId(22),
            new LoginIdentifier($loginIdentifier),
            new PasswordHash('safe-hash'),
            $userStatus,
        ));
        $session->userId = 11;
    }

    if ($representative === null && $withRepresentative) {
        $representative = new Representative(
            new RepresentativeId(33),
            new PersonId(22),
            null,
            $representativeStatus,
        );
    }
    $representatives = new RepresentativeAccessResolutionTest($representative);
    $getAuthenticatedUser = new GetAuthenticatedUser($session, $users);

    return [
        new GetAuthenticatedRepresentative($getAuthenticatedUser, $representatives),
        $users,
        $representatives,
    ];
}

function representativeAccessSession(GetAuthenticatedRepresentative $useCase): FakeSessionManager
{
    $getAuthenticatedUser = representativeAccessGetAuthenticatedUser($useCase);
    $property = (new ReflectionClass($getAuthenticatedUser))->getProperty('session');

    /** @var FakeSessionManager */
    return $property->getValue($getAuthenticatedUser);
}

function representativeAccessGetAuthenticatedUser(
    GetAuthenticatedRepresentative $useCase,
): GetAuthenticatedUser {
    $property = (new ReflectionClass($useCase))->getProperty('getAuthenticatedUser');

    /** @var GetAuthenticatedUser */
    return $property->getValue($useCase);
}

function representativeAccessSource(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    return is_string($file) ? (string) file_get_contents($file) : '';
}

function representativeAccessDirectorySource(string $relativeDirectory): string
{
    $files = glob(dirname(__DIR__) . '/' . $relativeDirectory . '/*.php') ?: [];
    $nested = glob(dirname(__DIR__) . '/' . $relativeDirectory . '/*/*.php') ?: [];

    return implode('', array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        array_merge($files, $nested),
    ));
}

final class RepresentativeAccessResolutionTest implements RepresentativeRepository
{
    /** @var list<int> */
    public array $requestedPersonIds = [];
    public int $saveCalls = 0;

    public function __construct(private readonly ?Representative $result)
    {
    }

    public function findById(RepresentativeId $id): ?Representative
    {
        return null;
    }

    public function findByPersonId(PersonId $personId): ?Representative
    {
        $this->requestedPersonIds[] = $personId->value();

        return $this->result === null ? null : clone $this->result;
    }

    public function save(Representative $representative): Representative
    {
        $this->saveCalls++;

        return $representative;
    }
}
