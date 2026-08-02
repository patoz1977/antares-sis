<?php

declare(strict_types=1);

namespace Tests;

use App\Representative\Domain\Exception\InvalidRepresentativeState;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use ReflectionClass;
use Tests\Support\TestRunner;
use TypeError;

function registerRepresentativeDomainTests(TestRunner $runner): void
{
    $runner->add('RepresentativeId requires a positive immutable identity and compares by value', function (): void {
        $identity = new RepresentativeId(10);
        $property = (new ReflectionClass(RepresentativeId::class))->getProperty('value');

        assertSameValue(10, $identity->value());
        assertSameValue(true, $identity->equals(new RepresentativeId(10)));
        assertSameValue(false, $identity->equals(new RepresentativeId(11)));
        assertSameValue(true, $property->isReadOnly());
        assertThrows(static fn (): RepresentativeId => new RepresentativeId(0), InvalidRepresentativeState::class);
        assertThrows(static fn (): RepresentativeId => new RepresentativeId(-1), InvalidRepresentativeState::class);
    });

    $runner->add('Representative PersonId is an independent positive immutable identity', function (): void {
        $identity = new PersonId(20);
        $property = (new ReflectionClass(PersonId::class))->getProperty('value');

        assertSameValue(20, $identity->value());
        assertSameValue(true, $identity->equals(new PersonId(20)));
        assertSameValue(false, $identity->equals(new PersonId(21)));
        assertSameValue(true, $property->isReadOnly());
        assertSameValue('App\\Representative\\Domain\\ValueObject', (new ReflectionClass($identity))->getNamespaceName());
        assertThrows(static fn (): PersonId => new PersonId(0), InvalidRepresentativeState::class);
        assertThrows(static fn (): PersonId => new PersonId(-1), InvalidRepresentativeState::class);
    });

    $runner->add('EmploymentInformation normalizes all approved optional fields', function (): void {
        $information = new EmploymentInformation(
            ' Teacher ',
            ' School SA ',
            ' Coordinator ',
            ' extension ABC / office ',
            ' representative@example.com ',
        );

        assertSameValue('Teacher', $information->occupation());
        assertSameValue('School SA', $information->companyName());
        assertSameValue('Coordinator', $information->position());
        assertSameValue('extension ABC / office', $information->workPhone());
        assertSameValue('representative@example.com', $information->workEmail());
        assertSameValue(false, $information->isEmpty());
    });

    $runner->add('EmploymentInformation accepts exact maximum lengths and free-form work phone', function (): void {
        $information = new EmploymentInformation(
            str_repeat('o', 150),
            str_repeat('c', 150),
            str_repeat('p', 150),
            str_repeat('x', 30),
            str_repeat('e', 64) . '@' . str_repeat('d', 63) . '.' . str_repeat('e', 63) . '.' . str_repeat('f', 61),
        );

        assertSameValue(150, mb_strlen((string) $information->occupation(), 'UTF-8'));
        assertSameValue(150, mb_strlen((string) $information->companyName(), 'UTF-8'));
        assertSameValue(150, mb_strlen((string) $information->position(), 'UTF-8'));
        assertSameValue(str_repeat('x', 30), $information->workPhone());
        assertSameValue(254, mb_strlen((string) $information->workEmail(), 'UTF-8'));
    });

    $runner->add('EmploymentInformation converts empty fields to a coherent absence', function (): void {
        $information = new EmploymentInformation(' ', '', null, "\t", '   ');

        assertSameValue(null, $information->occupation());
        assertSameValue(null, $information->companyName());
        assertSameValue(null, $information->position());
        assertSameValue(null, $information->workPhone());
        assertSameValue(null, $information->workEmail());
        assertSameValue(true, $information->isEmpty());
    });

    $runner->add('EmploymentInformation rejects only documented length and email violations', function (): void {
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(str_repeat('o', 151), null, null, null, null),
            InvalidRepresentativeState::class
        );
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(null, str_repeat('c', 151), null, null, null),
            InvalidRepresentativeState::class
        );
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(null, null, str_repeat('p', 151), null, null),
            InvalidRepresentativeState::class
        );
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(null, null, null, str_repeat('x', 31), null),
            InvalidRepresentativeState::class
        );
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(null, null, null, null, 'invalid-email'),
            InvalidRepresentativeState::class
        );
        assertThrows(
            static fn (): EmploymentInformation => new EmploymentInformation(null, null, null, null, str_repeat('e', 255)),
            InvalidRepresentativeState::class
        );
    });

    $runner->add('EmploymentInformation is immutable and compares by value', function (): void {
        $left = representativeEmploymentInformation();
        $reflection = new ReflectionClass(EmploymentInformation::class);

        assertSameValue(true, $left->equals(representativeEmploymentInformation()));
        assertSameValue(false, $left->equals(new EmploymentInformation('Other', null, null, null, null)));
        assertSameValue(true, $reflection->isReadOnly());
    });

    $runner->add('Representative constructs persisted and new aggregate states', function (): void {
        $persisted = representativeDomainFixture();
        $new = new Representative(
            null,
            new PersonId(30),
            null,
            RepresentativeStatus::Inactive,
        );

        assertSameValue(1, $persisted->id()?->value());
        assertSameValue(2, $persisted->personId()->value());
        assertSameValue('Teacher', $persisted->employmentInformation()?->occupation());
        assertSameValue(RepresentativeStatus::Active, $persisted->status());
        assertSameValue(null, $new->id());
        assertSameValue(30, $new->personId()->value());
        assertSameValue(null, $new->employmentInformation());
        assertSameValue(false, $new->isActive());
    });

    $runner->add('Representative requires PersonId through its typed construction contract', function (): void {
        assertThrows(
            static fn (): Representative => new Representative(null, null, null, RepresentativeStatus::Active),
            TypeError::class
        );
    });

    $runner->add('Representative identity and Person reference cannot be reassigned', function (): void {
        $representative = representativeDomainFixture();
        $reflection = new ReflectionClass(Representative::class);

        assertSameValue(true, $reflection->getProperty('id')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('personId')->isReadOnly());
        assertSameValue(false, method_exists($representative, 'setId'));
        assertSameValue(false, method_exists($representative, 'setPersonId'));

        $representative->deactivate();
        $representative->replaceEmploymentInformation(null);

        assertSameValue(1, $representative->id()?->value());
        assertSameValue(2, $representative->personId()->value());
    });

    $runner->add('Representative replaces and removes EmploymentInformation as one value', function (): void {
        $representative = representativeDomainFixture();
        $replacement = new EmploymentInformation('Engineer', null, null, 'desk ABC', null);

        $representative->replaceEmploymentInformation($replacement);
        assertSameValue(true, $representative->employmentInformation()?->equals($replacement));

        $representative->replaceEmploymentInformation(null);
        assertSameValue(null, $representative->employmentInformation());

        $representative->replaceEmploymentInformation(new EmploymentInformation('', ' ', null, null, null));
        assertSameValue(null, $representative->employmentInformation());
    });

    $runner->add('Representative keeps its previous state when replacement data is invalid', function (): void {
        $representative = representativeDomainFixture();
        $original = $representative->employmentInformation();

        assertThrows(
            static function () use ($representative): void {
                $representative->replaceEmploymentInformation(
                    new EmploymentInformation('Changed', null, null, str_repeat('x', 31), null)
                );
            },
            InvalidRepresentativeState::class
        );

        assertSameValue(true, $representative->employmentInformation()?->equals($original));
        assertSameValue(RepresentativeStatus::Active, $representative->status());
    });

    $runner->add('Representative activation lifecycle uses only GENERAL_STATUS codes', function (): void {
        $representative = representativeDomainFixture();

        $representative->deactivate();
        assertSameValue(RepresentativeStatus::Inactive, $representative->status());
        assertSameValue(false, $representative->isActive());

        $representative->activate();
        assertSameValue(RepresentativeStatus::Active, $representative->status());
        assertSameValue(true, $representative->isActive());
        assertSameValue('ACTIVE', RepresentativeStatus::Active->value);
        assertSameValue('INACTIVE', RepresentativeStatus::Inactive->value);
    });

    $runner->add('Representative Domain stays isolated and contains only the approved model', function (): void {
        $domainDirectory = __DIR__ . '/../app/Representative/Domain';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domainDirectory));
        $phpFiles = [];
        $source = '';

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = str_replace('\\', '/', $file->getPathname());
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        sort($phpFiles, SORT_STRING);

        assertSameValue(7, count($phpFiles));
        foreach (['App\\Person', 'Student', 'Family', 'Enrollment', 'PDO', 'Service', 'Controller', 'Http'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
    });
}

function representativeDomainFixture(): Representative
{
    return new Representative(
        new RepresentativeId(1),
        new PersonId(2),
        representativeEmploymentInformation(),
        RepresentativeStatus::Active,
    );
}

function representativeEmploymentInformation(): EmploymentInformation
{
    return new EmploymentInformation(
        'Teacher',
        'School SA',
        'Coordinator',
        '+593 99 123 4567',
        'representative@example.com',
    );
}
