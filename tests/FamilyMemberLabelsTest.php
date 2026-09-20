<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Infrastructure\Persistence\PdoFamilyMemberLabelsProvider;
use Core\Database\ConnectionFactory;
use Core\Database\ConnectionManager;
use Core\Database\DatabaseConfig;
use PDO;
use ReflectionProperty;
use Tests\Support\TestRunner;

function registerFamilyMemberLabelsTests(TestRunner $runner): void
{
    $runner->add('Family member labels resolve names in one family-scoped read without exposing another Family', function (): void {
        $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE persons (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, middle_name TEXT, first_surname TEXT NOT NULL, second_surname TEXT)');
        $database->exec('CREATE TABLE representatives (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL)');
        $database->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL)');
        $database->exec('CREATE TABLE relationship_types (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $database->exec('CREATE TABLE family_representatives (family_id INTEGER NOT NULL, representative_id INTEGER NOT NULL, relationship_type_id INTEGER NOT NULL)');
        $database->exec('CREATE TABLE family_students (family_id INTEGER NOT NULL, student_id INTEGER NOT NULL)');
        $database->exec("INSERT INTO persons VALUES (1, 'Ana', NULL, 'Pérez', NULL), (2, 'Luis', 'José', 'Pérez', 'Mora'), (3, 'Otra', NULL, 'Familia', NULL)");
        $database->exec("INSERT INTO representatives VALUES (10, 1), (30, 3)");
        $database->exec("INSERT INTO students VALUES (20, 2)");
        $database->exec("INSERT INTO relationship_types VALUES (1, 'Madre'), (2, 'Padre')");
        $database->exec('INSERT INTO family_representatives VALUES (100, 10, 1), (200, 30, 2)');
        $database->exec('INSERT INTO family_students VALUES (100, 20)');

        $manager = new ConnectionManager(new ConnectionFactory(), new DatabaseConfig([
            'driver' => 'sqlite', 'host' => '', 'port' => 0, 'database' => ':memory:',
            'username' => '', 'password' => '', 'charset' => '',
        ]));
        (new ReflectionProperty(ConnectionManager::class, 'connection'))->setValue($manager, $database);
        $provider = new PdoFamilyMemberLabelsProvider($manager);

        $firstFamily = $provider->forFamily(100);
        assertSameValue('Ana Pérez', $firstFamily->representative(10));
        assertSameValue('Luis José Pérez Mora', $firstFamily->student(20));
        assertSameValue('Madre', $firstFamily->relationship(1));
        assertSameValue('Representante no disponible', $firstFamily->representative(30));
        assertSameValue('Relación no disponible', $firstFamily->relationship(2));

        $secondFamily = $provider->forFamily(200);
        assertSameValue('Otra Familia', $secondFamily->representative(30));
        assertSameValue('Representante no disponible', $secondFamily->representative(10));
        assertSameValue('Estudiante no disponible', $secondFamily->student(20));
    });
}
