<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId as FamilyRepresentativeMembershipId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use App\IdentityAccess\Application\AuthorizedFamily;
use App\IdentityAccess\Application\FamilyContext;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\GetAuthorizedFamilies;
use App\IdentityAccess\Application\RepresentativeFamilyAccess;
use App\IdentityAccess\Application\RepresentativeFamilyContextSession;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\IdentityAccess\Application\SelectAuthorizedFamily;
use App\IdentityAccess\Application\Exception\FamilyContextNotAuthorized;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use App\IdentityAccess\Infrastructure\Session\PhpSessionManager;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use Core\Container\Container;
use Core\Session\SessionInterface;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerFamilyContextAuthorizationTests(TestRunner $runner): void
{
    $runner->add('No authenticated Representative or admin User has authorized Families', function (): void {
        $anonymous = familyContextAuthorizationFixture(withUser: false);
        $admin = familyContextAuthorizationFixture(withRepresentative: false, loginIdentifier: 'admin');

        assertSameValue(null, $anonymous['get']->handle());
        assertSameValue(null, $anonymous['resolve']->handle());
        assertSameValue(null, $admin['get']->handle());
        assertSameValue(null, $admin['resolve']->handle());
        assertSameValue(0, $anonymous['families']->saveCalls());
        assertSameValue(0, $admin['families']->saveCalls());
    });

    $runner->add('Representative without active membership receives an empty authorized set', function (): void {
        $fixture = familyContextAuthorizationFixture();

        $authorized = $fixture['get']->handle();

        assertSameValue([], $authorized?->families);
        assertSameValue(33, $authorized?->representative->representativeId);
        assertSameValue(0, $fixture['families']->saveCalls());
    });

    $runner->add('Authorized Families preserve exact stable identities names and Family status semantics', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(30, 'Thirty', [33]));
        $fixture['families']->seed(familyContextFamily(
            10,
            'Inactive Family Still Authorized',
            [33],
            status: FamilyStatus::Inactive,
        ));
        $fixture['families']->seed(familyContextFamily(20, 'Other Representative', [99]));
        $fixture['families']->seed(familyContextFamily(40, 'Historical Membership', [99], [33]));

        $authorized = $fixture['get']->handle();

        assertSameValue([10, 30], array_map(
            static fn (AuthorizedFamily $family): int => $family->familyId,
            $authorized?->families ?? [],
        ));
        assertSameValue(
            ['Inactive Family Still Authorized', 'Thirty'],
            array_map(
                static fn (AuthorizedFamily $family): string => $family->displayName,
                $authorized?->families ?? [],
            ),
        );
        assertSameValue(0, $fixture['families']->saveCalls());
    });

    $runner->add('Invalid persisted Family results never become authorization', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $valid = familyContextFamily(10, 'Valid', [33]);
        $incoherent = familyContextFamily(20, 'Wrong Representative', [99]);
        $unpersisted = Family::create(
            new DisplayName('Unpersisted'),
            FamilyStatus::Active,
            new FamilyRepresentativeId(33),
            new RelationshipTypeId(1),
            familyContextInstant('2026-01-01 00:00:00'),
        );

        foreach ([[$valid, $valid], [$incoherent], [$unpersisted]] as $results) {
            $get = new GetAuthorizedFamilies(
                $fixture['getRepresentative'],
                familyContextRepositoryReturning($results),
            );
            assertThrows($get->handle(...), InvalidPersistedFamilyResult::class);
        }
    });

    $runner->add('Zero authorized Families fail closed and clear any stale session context', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['session']->put('representative_family_context_id', 999);

        $access = $fixture['resolve']->handle();

        assertSameValue(true, $access instanceof RepresentativeFamilyAccess);
        assertSameValue([], $access?->authorizedFamilies);
        assertSameValue(null, $access?->context);
        assertSameValue(false, $access?->requiresSelection);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('One authorized Family auto-selects the exact effective context', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Only Family', [33]));

        $access = $fixture['resolve']->handle();

        assertSameValue(false, $access?->requiresSelection);
        assertSameValue(11, $access?->context?->userId);
        assertSameValue(22, $access?->context?->personId);
        assertSameValue(33, $access?->context?->representativeId);
        assertSameValue(10, $access?->context?->familyId);
        assertSameValue('Only Family', $access?->context?->familyDisplayName);
        assertSameValue(10, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Multiple authorized Families require selection without using IsPrimary', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Primary Here', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Also Primary Here', [33]));

        $access = $fixture['resolve']->handle();

        assertSameValue([10, 20], familyContextAuthorizedIds($access));
        assertSameValue(true, $access?->requiresSelection);
        assertSameValue(null, $access?->context);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Valid selection and Family change use only the current authorized set', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Family B', [33]));

        $contextA = $fixture['select']->handle(10);
        $resolvedA = $fixture['resolve']->handle();
        $contextB = $fixture['select']->handle(20);
        $resolvedB = $fixture['resolve']->handle();

        assertSameValue(10, $contextA->familyId);
        assertSameValue(10, $resolvedA?->context?->familyId);
        assertSameValue(20, $contextB->familyId);
        assertSameValue(20, $resolvedB?->context?->familyId);
        assertSameValue(20, $fixture['session']->get('representative_family_context_id'));
        assertSameValue(0, $fixture['families']->saveCalls());
    });

    $runner->add('Invalid nonexistent historical or other Representative selection preserves session', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Authorized', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Other', [99]));
        $fixture['families']->seed(familyContextFamily(30, 'Historical', [99], [33]));
        $fixture['session']->put('representative_family_context_id', 10);

        foreach ([0, -1, 20, 30, 999] as $familyId) {
            assertThrows(
                static fn (): FamilyContext => $fixture['select']->handle($familyId),
                FamilyContextNotAuthorized::class,
            );
            assertSameValue(10, $fixture['session']->get('representative_family_context_id'));
        }

        $admin = familyContextAuthorizationFixture(withRepresentative: false, loginIdentifier: 'admin');
        $admin['session']->put('representative_family_context_id', 10);
        assertThrows(
            static fn (): FamilyContext => $admin['select']->handle(10),
            FamilyContextNotAuthorized::class,
        );
        assertSameValue(10, $admin['session']->get('representative_family_context_id'));
    });

    $runner->add('Stale selected membership re-evaluates to zero Families and clears context', function (): void {
        $fixture = familyContextAuthorizationFixture();
        familyContextSeedSelectableFamily($fixture['families'], 10, 'Stale A');
        $fixture['select']->handle(10);

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $access = $fixture['resolve']->handle();

        assertSameValue([], familyContextAuthorizedIds($access));
        assertSameValue(null, $access?->context);
        assertSameValue(false, $access?->requiresSelection);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Stale selected membership auto-selects one remaining authorized Family', function (): void {
        $fixture = familyContextAuthorizationFixture();
        familyContextSeedSelectableFamily($fixture['families'], 10, 'Stale A');
        $fixture['families']->seed(familyContextFamily(20, 'Remaining B', [33]));
        $fixture['select']->handle(10);

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $access = $fixture['resolve']->handle();

        assertSameValue([20], familyContextAuthorizedIds($access));
        assertSameValue(20, $access?->context?->familyId);
        assertSameValue(false, $access?->requiresSelection);
        assertSameValue(20, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Stale selected membership requires selection when multiple Families remain', function (): void {
        $fixture = familyContextAuthorizationFixture();
        familyContextSeedSelectableFamily($fixture['families'], 10, 'Stale A');
        $fixture['families']->seed(familyContextFamily(20, 'Remaining B', [33]));
        $fixture['families']->seed(familyContextFamily(30, 'Remaining C', [33]));
        $fixture['select']->handle(10);

        familyContextEndRepresentativeMembership($fixture['families'], 10, 33);
        $access = $fixture['resolve']->handle();

        assertSameValue([20, 30], familyContextAuthorizedIds($access));
        assertSameValue(null, $access?->context);
        assertSameValue(true, $access?->requiresSelection);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Session accepts only positive integer Family context and revalidates every read', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['families']->seed(familyContextFamily(10, 'Authorized', [33]));
        $fixture['families']->seed(familyContextFamily(20, 'Authorized Too', [33]));

        foreach (['10', 0, -10, false, ['familyId' => 10]] as $invalid) {
            $fixture['session']->put('representative_family_context_id', $invalid);
            $access = $fixture['resolve']->handle();
            assertSameValue(true, $access?->requiresSelection);
            assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
        }

        $fixture['session']->put('representative_family_context_id', 999);
        assertSameValue(true, $fixture['resolve']->handle()?->requiresSelection);
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));
    });

    $runner->add('Logout and login regeneration cannot inherit Representative Family context', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $fixture['session']->put('representative_family_context_id', 10);
        $fixture['session']->destroy();
        assertSameValue(null, $fixture['session']->get('representative_family_context_id'));

        $storage = familyContextSessionStorage();
        $manager = new PhpSessionManager($storage);
        $manager->put('representative_family_context_id', 10);
        $manager->put('unrelated_key', 'preserved');
        $manager->regenerateForUser(44);

        assertSameValue(null, $manager->get('representative_family_context_id'));
        assertSameValue('preserved', $manager->get('unrelated_key'));
        assertSameValue(44, $manager->authenticatedUserId());
    });

    $runner->add('Family context Application remains isolated session-minimal and read-only', function (): void {
        $source = '';
        foreach ([
            GetAuthorizedFamilies::class,
            ResolveFamilyContext::class,
            SelectAuthorizedFamily::class,
            RepresentativeFamilyContextSession::class,
        ] as $class) {
            $source .= familyContextSource($class);
        }
        foreach ([
            'Infrastructure', 'PDO', 'Http', 'Student', 'Transaction',
            'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'FamilyStatus', 'isPrimary',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }

        $sessionSource = familyContextSource(RepresentativeFamilyContextSession::class);
        assertSameValue(false, str_contains($sessionSource, 'representativeId'));
        assertSameValue(false, str_contains($sessionSource, 'personId'));
        assertSameValue(false, str_contains($sessionSource, 'AuthenticatedRepresentative'));

        $identityDomain = representativeAccessDirectorySource('app/IdentityAccess/Domain');
        $familyDomain = representativeAccessDirectorySource('app/Family/Domain');
        assertSameValue(false, str_contains($identityDomain, 'Family'));
        assertSameValue(false, str_contains($familyDomain, 'IdentityAccess'));
    });

    $runner->add('Family context wiring remains reusable by Portal delivery', function (): void {
        $fixture = familyContextAuthorizationFixture();
        $container = new Container();
        $container->instance(GetAuthenticatedRepresentative::class, $fixture['getRepresentative']);
        $container->instance(FamilyRepository::class, $fixture['families']);
        $container->instance(
            \App\IdentityAccess\Application\Contract\SessionManager::class,
            $fixture['session'],
        );
        $container->singleton(GetAuthorizedFamilies::class, GetAuthorizedFamilies::class);
        $container->singleton(
            RepresentativeFamilyContextSession::class,
            RepresentativeFamilyContextSession::class,
        );
        $container->singleton(ResolveFamilyContext::class, ResolveFamilyContext::class);
        $container->singleton(SelectAuthorizedFamily::class, SelectAuthorizedFamily::class);

        assertSameValue(true, $container->make(GetAuthorizedFamilies::class) instanceof GetAuthorizedFamilies);
        assertSameValue(true, $container->make(ResolveFamilyContext::class) instanceof ResolveFamilyContext);
        assertSameValue(true, $container->make(SelectAuthorizedFamily::class) instanceof SelectAuthorizedFamily);

        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        foreach ([GetAuthorizedFamilies::class, ResolveFamilyContext::class, SelectAuthorizedFamily::class] as $class) {
            $shortName = (new ReflectionClass($class))->getShortName();
            assertSameValue(true, str_contains(
                $bootstrap,
                '$container->singleton(' . $shortName . '::class, ' . $shortName . '::class);',
            ));
        }

        foreach ([GetAuthorizedFamilies::class, ResolveFamilyContext::class, SelectAuthorizedFamily::class] as $class) {
            assertSameValue(false, str_contains(familyContextSource($class), 'RepresentativePortalController'));
        }
    });

    $runner->add('Family access result exposes only safe coherent context identity', function (): void {
        $family = new AuthorizedFamily(10, 'Family');
        $representative = new \App\IdentityAccess\Application\AuthenticatedRepresentative(
            11,
            22,
            33,
            'representative-22',
        );
        $context = FamilyContext::from($representative, $family);
        $access = new RepresentativeFamilyAccess($representative, [$family], $context, false);

        assertSameValue(
            ['userId', 'personId', 'representativeId', 'familyId', 'familyDisplayName'],
            array_map(
                static fn ($property): string => $property->getName(),
                (new ReflectionClass(FamilyContext::class))->getProperties(),
            ),
        );
        assertSameValue(10, $access->context?->familyId);
        assertThrows(
            static fn (): RepresentativeFamilyAccess => new RepresentativeFamilyAccess(
                $representative,
                [$family],
                null,
                false,
            ),
            \InvalidArgumentException::class,
        );
    });
}

/** @return array<string, mixed> */
function familyContextAuthorizationFixture(
    bool $withUser = true,
    bool $withRepresentative = true,
    string $loginIdentifier = 'representative-22',
): array {
    $session = new FakeSessionManager();
    $users = new InMemoryRepresentativeUserRepository();
    if ($withUser) {
        $users->seed(new User(
            new UserId(11),
            new UserPersonId(22),
            new LoginIdentifier($loginIdentifier),
            new PasswordHash('safe-hash'),
            UserStatus::Active,
        ));
        $session->userId = 11;
    }

    $representative = $withRepresentative
        ? new Representative(
            new RepresentativeId(33),
            new PersonId(22),
            null,
            RepresentativeStatus::Active,
        )
        : null;
    $representatives = new RepresentativeAccessResolutionTest($representative);
    $getRepresentative = new GetAuthenticatedRepresentative(
        new GetAuthenticatedUser($session, $users),
        $representatives,
    );
    $families = new InMemoryFamilyApplicationRepository();
    $get = new GetAuthorizedFamilies($getRepresentative, $families);
    $familySession = new RepresentativeFamilyContextSession($session);

    return [
        'session' => $session,
        'users' => $users,
        'representatives' => $representatives,
        'families' => $families,
        'getRepresentative' => $getRepresentative,
        'get' => $get,
        'resolve' => new ResolveFamilyContext($get, $familySession),
        'select' => new SelectAuthorizedFamily($get, $familySession),
    ];
}

/**
 * @param list<int> $activeRepresentativeIds
 * @param list<int> $historicalRepresentativeIds
 */
function familyContextFamily(
    int $id,
    string $displayName,
    array $activeRepresentativeIds,
    array $historicalRepresentativeIds = [],
    FamilyStatus $status = FamilyStatus::Active,
): Family {
    $memberships = [];
    $membershipId = $id * 100;
    foreach ($activeRepresentativeIds as $index => $representativeId) {
        $memberships[] = new FamilyRepresentative(
            new FamilyRepresentativeMembershipId(++$membershipId),
            new FamilyRepresentativeId($representativeId),
            new RelationshipTypeId(1),
            $index === 0,
            familyContextInstant('2026-01-01 00:00:00'),
            null,
        );
    }
    foreach ($historicalRepresentativeIds as $representativeId) {
        $memberships[] = new FamilyRepresentative(
            new FamilyRepresentativeMembershipId(++$membershipId),
            new FamilyRepresentativeId($representativeId),
            new RelationshipTypeId(1),
            false,
            familyContextInstant('2025-01-01 00:00:00'),
            familyContextInstant('2025-02-01 00:00:00'),
        );
    }

    return Family::reconstitute(
        new FamilyId($id),
        new DisplayName($displayName),
        $status,
        $memberships,
        [],
    );
}

function familyContextSeedSelectableFamily(
    InMemoryFamilyApplicationRepository $families,
    int $familyId,
    string $displayName,
): void {
    $families->seed(familyContextFamily($familyId, $displayName, [99, 33]));
}

function familyContextEndRepresentativeMembership(
    InMemoryFamilyApplicationRepository $families,
    int $familyId,
    int $representativeId,
): void {
    $family = $families->findById(new FamilyId($familyId));
    if ($family === null) {
        throw new \RuntimeException('Family fixture was not found.');
    }
    $family->endRepresentativeMembership(
        new FamilyRepresentativeId($representativeId),
        familyContextInstant('2026-02-01 00:00:00'),
    );
    $families->save($family);
}

/** @param list<Family> $results */
function familyContextRepositoryReturning(array $results): FamilyRepository
{
    return new class($results) implements FamilyRepository {
        /** @param list<Family> $results */
        public function __construct(private readonly array $results)
        {
        }

        public function findById(FamilyId $id): ?Family
        {
            return null;
        }

        public function findActiveByRepresentativeId(FamilyRepresentativeId $representativeId): array
        {
            return $this->results;
        }

        public function findActiveByStudentId(StudentId $studentId): ?Family
        {
            return null;
        }

        public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
        {
            return null;
        }

        public function save(Family $family): Family
        {
            return $family;
        }
    };
}

function familyContextInstant(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

function familyContextAuthorizedIds(?RepresentativeFamilyAccess $access): array
{
    return array_map(
        static fn (AuthorizedFamily $family): int => $family->familyId,
        $access?->authorizedFamilies ?? [],
    );
}

function familyContextSource(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    return is_string($file) ? (string) file_get_contents($file) : '';
}

function familyContextSessionStorage(): SessionInterface
{
    return new class implements SessionInterface {
        /** @var array<string, mixed> */
        private array $values = [];

        public function start(): void
        {
        }

        public function regenerate(): void
        {
        }

        public function has(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->values[$key] = $value;
        }

        public function remove(string $key): void
        {
            unset($this->values[$key]);
        }

        public function clear(): void
        {
            $this->values = [];
        }

        public function destroy(): void
        {
            $this->clear();
        }
    };
}
