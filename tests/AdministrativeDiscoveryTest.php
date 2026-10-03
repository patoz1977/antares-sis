<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\Discovery\Dto\FamilyDiscoveryRow;
use App\Family\Application\Discovery\FamilyDiscoveryCriteria;
use App\Family\Application\Discovery\FamilyDiscoveryQuery;
use App\Family\Application\Discovery\SearchFamilies;
use App\Family\Application\Discovery\Exception\InvalidFamilyDiscoveryCriteria;
use App\Family\Http\FamilyDiscoveryController;
use App\Family\Infrastructure\Persistence\PdoFamilyDiscoveryQuery;
use App\Person\Application\Discovery\Dto\PersonDiscoveryRow;
use App\Person\Application\Discovery\Exception\InvalidPersonDiscoveryCriteria;
use App\Person\Application\Discovery\PersonDiscoveryCriteria;
use App\Person\Application\Discovery\PersonDiscoveryQuery;
use App\Person\Application\Discovery\SearchPersons;
use App\Person\Http\PersonDiscoveryController;
use App\Person\Infrastructure\Persistence\PdoPersonDiscoveryQuery;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\TestRunner;

function registerAdministrativeDiscoveryTests(TestRunner $runner): void
{
    $runner->add('Phase 4 Person criteria expose exactly approved fields and normalize without fuzzy semantics', function (): void {
        $cases = [
            ['first_name', '  Ana   María ', 'Ana María'],
            ['middle_name', ' Mar ', 'Mar'],
            ['first_surname', ' Del   Río ', 'Del Río'],
            ['second_surname', ' Paz ', 'Paz'],
            ['identification_number', ' ab-123 ', 'ab-123'],
            ['personal_email', ' ana@example.test ', 'ana@example.test'],
        ];
        foreach ($cases as [$field, $input, $expected]) {
            $criteria = new PersonDiscoveryCriteria($field, $input);
            assertSameValue([$field, $expected], [$criteria->field->value, $criteria->value]);
        }
        assertSameValue('AB  123', (new PersonDiscoveryCriteria('identification_number', ' AB  123 '))->value);

        foreach (['first_name', 'middle_name', 'first_surname', 'second_surname'] as $field) {
            administrativeDiscoveryAssertThrows(
                static fn (): PersonDiscoveryCriteria => new PersonDiscoveryCriteria($field, 'ab'),
                InvalidPersonDiscoveryCriteria::class,
            );
        }
        foreach (['mobile_phone', 'landline_phone', 'phone', 'unknown'] as $field) {
            administrativeDiscoveryAssertThrows(
                static fn (): PersonDiscoveryCriteria => new PersonDiscoveryCriteria($field, '0999999999'),
                InvalidPersonDiscoveryCriteria::class,
            );
        }
    });

    $runner->add('Phase 4 Family criteria preserve exact FamilyCode and reuse all approved Person semantics', function (): void {
        assertSameValue('F00000001', (new FamilyDiscoveryCriteria('family_code', ' F00000001 '))->value);
        assertSameValue('Familia Paz', (new FamilyDiscoveryCriteria('display_name', ' Familia   Paz '))->value);
        foreach ([
            'person_first_name', 'person_middle_name', 'person_first_surname',
            'person_second_surname', 'person_identification_number', 'person_personal_email',
        ] as $field) {
            $value = str_contains($field, 'email') ? 'member@example.test'
                : (str_contains($field, 'identification') ? 'ID-9' : 'Ann');
            assertSameValue(true, (new FamilyDiscoveryCriteria($field, $value))->isPersonSearch());
        }
        administrativeDiscoveryAssertThrows(
            static fn (): FamilyDiscoveryCriteria => new FamilyDiscoveryCriteria('family_code', 'f00000001'),
            InvalidFamilyDiscoveryCriteria::class,
        );
        administrativeDiscoveryAssertThrows(
            static fn (): FamilyDiscoveryCriteria => new FamilyDiscoveryCriteria('display_name', 'ab'),
            InvalidFamilyDiscoveryCriteria::class,
        );
    });

    $runner->add('Phase 4 Application exposes immutable DTOs and caps 31-row probes at 30 visible', function (): void {
        $rows = [];
        for ($id = 1; $id <= 31; $id++) {
            $rows[] = new PersonDiscoveryRow($id, 'Name', null, 'Surname', null, null, null);
        }
        $query = new class($rows) implements PersonDiscoveryQuery {
            public function __construct(private array $rows) {}
            public function search(PersonDiscoveryCriteria $criteria): array { return $this->rows; }
        };
        $result = (new SearchPersons($query))->handle(new PersonDiscoveryCriteria('first_name', 'Nam'));
        assertSameValue([30, true], [count($result->rows), $result->hasMore]);

        $familyRows = [];
        for ($id = 1; $id <= 31; $id++) {
            $familyRows[] = new FamilyDiscoveryRow($id, 'F' . str_pad((string) $id, 8, '0', STR_PAD_LEFT), 'Family', 'ACTIVE', []);
        }
        $familyQuery = new class($familyRows) implements FamilyDiscoveryQuery {
            public function __construct(private array $rows) {}
            public function search(FamilyDiscoveryCriteria $criteria): array { return $this->rows; }
        };
        $familyResult = (new SearchFamilies($familyQuery))->handle(new FamilyDiscoveryCriteria('display_name', 'Fam'));
        assertSameValue([30, true], [count($familyResult->rows), $familyResult->hasMore]);
        foreach ([PersonDiscoveryRow::class, \App\Person\Application\Discovery\Dto\PersonDiscoveryResult::class,
            FamilyDiscoveryRow::class, \App\Family\Application\Discovery\Dto\FamilyDiscoveryResult::class,
        ] as $dto) {
            assertSameValue(true, (new ReflectionClass($dto))->isReadOnly());
        }
    });

    $runner->add('Phase 4 PDO Person search supports every criterion exact or word-prefix with deterministic order', function (): void {
        [$manager] = administrativeDiscoveryFixture();
        $query = new PdoPersonDiscoveryQuery($manager);
        $expectations = [
            ['first_name', 'Mar', [2, 1]],
            ['middle_name', 'Isa', [2]],
            ['first_surname', 'Pér', [2, 1]],
            ['second_surname', 'Río', [2]],
            ['identification_number', ' ab-002 ', [2]],
            ['personal_email', 'maria@example.test', [2]],
        ];
        foreach ($expectations as [$field, $value, $ids]) {
            assertSameValue($ids, array_column($query->search(new PersonDiscoveryCriteria($field, $value)), 'personId'), $field);
        }
        assertSameValue([], $query->search(new PersonDiscoveryCriteria('first_name', 'Nobody')));
    });

    $runner->add('Phase 4 PDO Family search is case-sensitive direct and resolves only active matching members', function (): void {
        [$manager] = administrativeDiscoveryFixture();
        $query = new PdoFamilyDiscoveryQuery($manager);
        assertSameValue([10], array_column($query->search(new FamilyDiscoveryCriteria('family_code', 'F00000010')), 'familyId'));
        assertSameValue([], $query->search(new FamilyDiscoveryCriteria('family_code', 'F00000011')));
        assertSameValue([11, 10], array_column($query->search(new FamilyDiscoveryCriteria('display_name', 'Familia')), 'familyId'));

        $families = $query->search(new FamilyDiscoveryCriteria('person_first_name', 'Mar'));
        assertSameValue([11, 10], array_column($families, 'familyId'));
        assertSameValue(['María Isabel Pérez Del Río — Estudiante'], array_map(
            static fn ($member): string => $member->fullName . ' — ' . $member->role,
            $families[0]->matchedMembers,
        ));
        assertSameValue([
            'María Isabel Pérez Del Río — Estudiante',
            'Marcos José Pérez Gómez — Padre',
        ], array_map(
            static fn ($member): string => $member->fullName . ' — ' . $member->role,
            $families[1]->matchedMembers,
        ));
        assertSameValue(2, count($families));
        foreach ([
            ['person_middle_name', 'Isa'],
            ['person_first_surname', 'Pér'],
            ['person_second_surname', 'Del'],
            ['person_identification_number', 'AB-002'],
            ['person_personal_email', 'maria@example.test'],
        ] as [$field, $value]) {
            assertSameValue([11, 10], array_column(
                $query->search(new FamilyDiscoveryCriteria($field, $value)),
                'familyId',
            ), $field);
        }
        assertSameValue([], $query->search(new FamilyDiscoveryCriteria('person_first_name', 'His')));
    });

    $runner->add('Phase 4 PDO queries are prepared read-only bounded projections outside transactional repositories', function (): void {
        foreach ([
            'app/Person/Infrastructure/Persistence/PdoPersonDiscoveryQuery.php',
            'app/Family/Infrastructure/Persistence/PdoFamilyDiscoveryQuery.php',
        ] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $path);
            deliveryAssertContains('->prepare(', $source);
            deliveryAssertContains('LIMIT 31', $source);
            foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'PersonRepository', 'FamilyRepository'] as $forbidden) {
                assertSameValue(false, str_contains($source, $forbidden), $path . ': ' . $forbidden);
            }
        }
    });

    $runner->add('Phase 4 Family PDO adapter does not depend on Person infrastructure internals', function (): void {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/Family/Infrastructure/Persistence/PdoFamilyDiscoveryQuery.php',
        );

        foreach (['App\\Person\\Infrastructure', 'PdoPersonDiscoveryQuery'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        assertSameValue(true, (new ReflectionMethod(PdoPersonDiscoveryQuery::class, 'predicate'))->isPrivate());
    });

    $runner->add('Phase 4 Delivery uses POST CSRF no-store safe escaping and existing detail navigation', function (): void {
        $personQuery = new class implements PersonDiscoveryQuery {
            public function search(PersonDiscoveryCriteria $criteria): array
            {
                return [new PersonDiscoveryRow(7, '<Ana>', null, 'Pérez &', null, '<ID>', 'a&b@example.test')];
            }
        };
        deliveryRequest('POST', '/persons/search', [
            '_csrf_token' => 'delivery-csrf', 'criterion' => 'first_name', 'value' => 'Ana',
        ]);
        $html = (new PersonDiscoveryController(new SearchPersons($personQuery), new FakeDeliveryCsrf()))->search();
        foreach (['&lt;Ana&gt;', 'Pérez &amp;', '&lt;ID&gt;', 'a&amp;b@example.test', '/persons/show?id=7'] as $expected) {
            deliveryAssertContains($expected, $html);
        }

        $familyQuery = new class implements FamilyDiscoveryQuery {
            public function search(FamilyDiscoveryCriteria $criteria): array
            {
                return [new FamilyDiscoveryRow(9, 'F00000009', '<Familia>', 'ACTIVE', [
                    new \App\Family\Application\Discovery\Dto\FamilyMatchedMember('<Miembro>', 'Padre & tutor'),
                ])];
            }
        };
        deliveryRequest('POST', '/families/search', [
            '_csrf_token' => 'delivery-csrf', 'criterion' => 'display_name', 'value' => 'Fam',
        ]);
        $html = (new FamilyDiscoveryController(new SearchFamilies($familyQuery), new FakeDeliveryCsrf()))->search();
        foreach (['&lt;Familia&gt;', '&lt;Miembro&gt;', 'Padre &amp; tutor', '/families/show?id=9'] as $expected) {
            deliveryAssertContains($expected, $html);
        }

        deliveryRequest('POST', '/persons/search', [
            '_csrf_token' => 'invalid', 'criterion' => 'personal_email', 'value' => 'secret@example.test',
        ]);
        $rejected = (new PersonDiscoveryController(new SearchPersons($personQuery), new FakeDeliveryCsrf()))->search();
        deliveryAssertContains('No se pudo verificar la solicitud.', $rejected);

        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        foreach (["post('/persons/search'", "post('/families/search'"] as $route) {
            deliveryAssertContains($route, $routes);
        }
        assertSameValue(false, str_contains($routes, "get('/persons/search'"));
        assertSameValue(false, str_contains($routes, "get('/families/search'"));
        deliveryAssertContains("[\$personDiscoveryController, 'search'], \$personMiddleware", $routes);
        deliveryAssertContains("[\$familyDiscoveryController, 'search'], \$familyMiddleware", $routes);
        foreach (['app/Person/Http/PersonDiscoveryController.php', 'app/Family/Http/FamilyDiscoveryController.php'] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $path);
            deliveryAssertContains("header('Cache-Control: no-store')", $source);
            foreach (['SessionManager', 'Location:', 'error_log', 'Logger'] as $forbidden) {
                assertSameValue(false, str_contains($source, $forbidden), $path . ': ' . $forbidden);
            }
        }
    });

    $runner->add('Phase 4 UI removes raw lookup IDs and contains no phone search or pagination', function (): void {
        $persons = (string) file_get_contents(dirname(__DIR__) . '/resources/views/persons/index.php');
        $families = (string) file_get_contents(dirname(__DIR__) . '/resources/views/families/index.php');
        foreach (['type="number"', 'name="id"', 'Consultar persona por ID', 'Consultar familia por ID', 'pagination', 'infinite'] as $forbidden) {
            assertSameValue(false, str_contains($persons . $families, $forbidden), $forbidden);
        }
        assertSameValue(false, str_contains($persons, 'phone'));
        assertSameValue(false, str_contains($families, 'person_phone'));
        foreach (['method="post"', 'name="_csrf_token"', 'table-responsive', '<caption', 'Abrir detalle'] as $expected) {
            deliveryAssertContains($expected, $persons);
            deliveryAssertContains($expected, $families);
        }
    });
}

/** @return array{\Core\Database\ConnectionManager, PDO} */
function administrativeDiscoveryFixture(): array
{
    $manager = familySqliteManager();
    $pdo = $manager->connection();
    $pdo->exec('PRAGMA case_sensitive_like = OFF');
    $pdo->exec('CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE persons (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, middle_name TEXT, first_surname TEXT NOT NULL, second_surname TEXT, document_number TEXT, email TEXT)');
    $pdo->exec('CREATE TABLE representatives (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE families (id INTEGER PRIMARY KEY, family_code TEXT NOT NULL COLLATE BINARY, display_name TEXT NOT NULL, status_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE relationship_types (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE family_representatives (id INTEGER PRIMARY KEY, family_id INTEGER NOT NULL, representative_id INTEGER NOT NULL, relationship_type_id INTEGER NOT NULL, ended_at TEXT)');
    $pdo->exec('CREATE TABLE family_students (id INTEGER PRIMARY KEY, family_id INTEGER NOT NULL, student_id INTEGER NOT NULL, ended_at TEXT)');
    $pdo->exec("INSERT INTO status_types VALUES (1,'GENERAL_STATUS')");
    $pdo->exec("INSERT INTO statuses VALUES (1,1,'ACTIVE'),(2,1,'INACTIVE')");
    $pdo->exec("INSERT INTO persons VALUES (1,'Marcos','José','Pérez','Gómez','AB-001','marcos@example.test'),(2,'María','Isabel','Pérez','Del Río','AB-002','maria@example.test'),(3,'Historical',NULL,'Member',NULL,'OLD-3','old@example.test')");
    $pdo->exec("INSERT INTO representatives VALUES (101,1),(103,3)");
    $pdo->exec("INSERT INTO students VALUES (202,2)");
    $pdo->exec("INSERT INTO families VALUES (10,'F00000010','Familia Zeta',1),(11,'F00000020','Familia Alfa',2)");
    $pdo->exec("INSERT INTO relationship_types VALUES (1,'Padre')");
    $pdo->exec("INSERT INTO family_representatives VALUES (1,10,101,1,NULL),(2,10,103,1,'2026-01-01')");
    $pdo->exec("INSERT INTO family_students VALUES (1,10,202,NULL),(2,11,202,NULL),(3,11,202,'2026-01-01')");

    return [$manager, $pdo];
}

function administrativeDiscoveryAssertThrows(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (\Throwable $exception) {
        assertSameValue($expected, $exception::class);

        return;
    }

    throw new \RuntimeException('Expected exception was not thrown.');
}
