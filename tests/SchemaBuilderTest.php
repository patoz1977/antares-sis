<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\Schema\Blueprint;
use Core\Database\Schema\SchemaBuilder;

$builder = new SchemaBuilder();
$blueprint = new Blueprint('users');

$blueprint->id();
$blueprint->string('name');
$blueprint->integer('age')->nullable();
$blueprint->boolean('active')->default(true);
$blueprint->timestamps();

$method = new ReflectionMethod(SchemaBuilder::class, 'compileCreateTable');
$method->setAccessible(true);
$sql = $method->invoke($builder, $blueprint);

if (!str_contains($sql, 'CREATE TABLE IF NOT EXISTS `users`')) {
    throw new RuntimeException('Expected CREATE TABLE SQL was not generated.');
}

if (!str_contains($sql, '`id` BIGINT UNSIGNED')) {
    throw new RuntimeException('Expected id column definition was not generated.');
}

echo "Schema builder test passed.\n";
