<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgement;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementCompletionId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerInstitutionalAcknowledgementsDomainTests(TestRunner $runner): void
{
    $runner->add('Institutional Acknowledgements identities require positive immutable values', function (): void {
        $identities = [
            new AcknowledgementRequirementId(1),
            new RepresentativeAcknowledgementCompletionId(2),
            new RepresentativeAcknowledgementId(3),
            new AcademicPeriodId(4),
            new RepresentativeId(5),
        ];

        foreach ($identities as $identity) {
            $class = $identity::class;
            assertSameValue(true, (new ReflectionClass($class))->isReadOnly());
            assertSameValue(true, $identity->equals(new $class($identity->value())));
            assertSameValue(false, $identity->equals(new $class($identity->value() + 1)));
            assertThrows(static fn (): object => new $class(0), InvalidInstitutionalAcknowledgementState::class);
            assertThrows(static fn (): object => new $class(-1), InvalidInstitutionalAcknowledgementState::class);
        }
    });

    $runner->add('Requirement text values normalize and compare within approved limits', function (): void {
        $title = new AcknowledgementRequirementTitle('  Institutional policy  ');
        $url = new AcknowledgementRequirementUrl('  internal-reference/path  ');
        $reference = new AcknowledgementOfficialReference('  RES-2026-01  ');

        assertSameValue('Institutional policy', $title->value());
        assertSameValue('internal-reference/path', $url->value());
        assertSameValue('RES-2026-01', $reference->value());
        assertSameValue(true, $title->equals(new AcknowledgementRequirementTitle('Institutional policy')));
        assertSameValue(true, $url->equals(new AcknowledgementRequirementUrl('internal-reference/path')));
        assertSameValue(true, $reference->equals(new AcknowledgementOfficialReference('RES-2026-01')));
        assertSameValue(true, (new ReflectionClass($title))->isReadOnly());
        assertSameValue(true, (new ReflectionClass($url))->isReadOnly());
        assertSameValue(true, (new ReflectionClass($reference))->isReadOnly());
    });

    $runner->add('Requirement text values enforce presence and exact maximum lengths only', function (): void {
        assertSameValue(200, mb_strlen((new AcknowledgementRequirementTitle(str_repeat('t', 200)))->value(), 'UTF-8'));
        assertSameValue(500, mb_strlen((new AcknowledgementRequirementUrl(str_repeat('u', 500)))->value(), 'UTF-8'));
        assertSameValue(255, mb_strlen((new AcknowledgementOfficialReference(str_repeat('r', 255)))->value(), 'UTF-8'));

        foreach (['', ' ', "\t"] as $blank) {
            assertThrows(
                static fn (): AcknowledgementRequirementTitle => new AcknowledgementRequirementTitle($blank),
                InvalidInstitutionalAcknowledgementState::class
            );
            assertThrows(
                static fn (): AcknowledgementRequirementUrl => new AcknowledgementRequirementUrl($blank),
                InvalidInstitutionalAcknowledgementState::class
            );
            assertThrows(
                static fn (): AcknowledgementOfficialReference => new AcknowledgementOfficialReference($blank),
                InvalidInstitutionalAcknowledgementState::class
            );
        }

        assertThrows(
            static fn (): AcknowledgementRequirementTitle => new AcknowledgementRequirementTitle(str_repeat('t', 201)),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn (): AcknowledgementRequirementUrl => new AcknowledgementRequirementUrl(str_repeat('u', 501)),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn (): AcknowledgementOfficialReference => new AcknowledgementOfficialReference(str_repeat('r', 256)),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Requirement URL does not invent an undocumented protocol rule', function (): void {
        foreach (['policy.pdf', '/institution/resources/policy', 'custom:reference'] as $value) {
            assertSameValue($value, (new AcknowledgementRequirementUrl($value))->value());
        }
    });

    $runner->add('Requirement creation keeps a nullable identity and immutable AcademicPeriod', function (): void {
        $requirement = newInstitutionalRequirement();
        $reflection = new ReflectionClass($requirement);

        assertSameValue(null, $requirement->id());
        assertSameValue(10, $requirement->academicPeriodId()->value());
        assertSameValue('Family handbook', $requirement->title()->value());
        assertSameValue('resources/handbook', $requirement->url()->value());
        assertSameValue(null, $requirement->officialReference());
        assertSameValue(AcknowledgementRequirementStatus::Active, $requirement->status());
        assertSameValue(true, $reflection->getProperty('id')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('academicPeriodId')->isReadOnly());
        assertSameValue(false, method_exists($requirement, 'setId'));
        assertSameValue(false, method_exists($requirement, 'setAcademicPeriodId'));
        assertSameValue(false, method_exists($requirement, 'delete'));
    });

    $runner->add('Requirement reconstitution preserves persisted identity and approved state', function (): void {
        $requirement = persistedInstitutionalRequirement(11, 12, AcknowledgementRequirementStatus::Inactive);

        assertSameValue(11, $requirement->id()?->value());
        assertSameValue(12, $requirement->academicPeriodId()->value());
        assertSameValue('Requirement 11', $requirement->title()->value());
        assertSameValue('reference/11', $requirement->url()->value());
        assertSameValue('OFFICIAL-11', $requirement->officialReference()?->value());
        assertSameValue(false, $requirement->isActive());
    });

    $runner->add('Requirement update changes all approved fields before first acknowledgement', function (): void {
        $requirement = newInstitutionalRequirement();

        $requirement->update(
            new AcknowledgementRequirementTitle('Updated title'),
            new AcknowledgementRequirementUrl('updated/location'),
            new AcknowledgementOfficialReference('UPDATED-REF'),
            false,
        );

        assertSameValue('Updated title', $requirement->title()->value());
        assertSameValue('updated/location', $requirement->url()->value());
        assertSameValue('UPDATED-REF', $requirement->officialReference()?->value());

        $requirement->update(
            new AcknowledgementRequirementTitle('Updated again'),
            new AcknowledgementRequirementUrl('updated/again'),
            null,
            false,
        );
        assertSameValue(null, $requirement->officialReference());
    });

    $runner->add('Requirement blocks protected changes after first acknowledgement', function (): void {
        $requirement = persistedInstitutionalRequirement(1, 10);

        assertThrows(
            static fn () => $requirement->update(
                new AcknowledgementRequirementTitle('Different title'),
                new AcknowledgementRequirementUrl('new/url'),
                new AcknowledgementOfficialReference('OFFICIAL-1'),
                true,
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn () => $requirement->update(
                new AcknowledgementRequirementTitle('Requirement 1'),
                new AcknowledgementRequirementUrl('new/url'),
                null,
                true,
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn () => $requirement->update(
                new AcknowledgementRequirementTitle('Requirement 1'),
                new AcknowledgementRequirementUrl('new/url'),
                new AcknowledgementOfficialReference('DIFFERENT'),
                true,
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Requirement protected update validates before mutation and accepts normalized equality', function (): void {
        $requirement = persistedInstitutionalRequirement(1, 10);

        assertThrows(
            static fn () => $requirement->update(
                new AcknowledgementRequirementTitle('Different title'),
                new AcknowledgementRequirementUrl('must/not/be/stored'),
                null,
                true,
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertSameValue('Requirement 1', $requirement->title()->value());
        assertSameValue('reference/1', $requirement->url()->value());
        assertSameValue('OFFICIAL-1', $requirement->officialReference()?->value());

        $requirement->update(
            new AcknowledgementRequirementTitle('  Requirement 1  '),
            new AcknowledgementRequirementUrl('new/url'),
            new AcknowledgementOfficialReference('  OFFICIAL-1  '),
            true,
        );
        assertSameValue('new/url', $requirement->url()->value());
    });

    $runner->add('Requirement permits normalized null reference equality after acknowledgement', function (): void {
        $requirement = newInstitutionalRequirement();

        $requirement->update(
            new AcknowledgementRequirementTitle(' Family handbook '),
            new AcknowledgementRequirementUrl('replacement/url'),
            null,
            true,
        );

        assertSameValue('replacement/url', $requirement->url()->value());
        assertSameValue(null, $requirement->officialReference());
    });

    $runner->add('Requirement status lifecycle uses only GENERAL_STATUS codes independently', function (): void {
        $requirement = persistedInstitutionalRequirement(1, 10);

        $requirement->deactivate();
        assertSameValue(AcknowledgementRequirementStatus::Inactive, $requirement->status());
        assertSameValue(false, $requirement->isActive());

        $requirement->activate();
        assertSameValue(AcknowledgementRequirementStatus::Active, $requirement->status());
        assertSameValue(true, $requirement->isActive());
        assertSameValue('ACTIVE', AcknowledgementRequirementStatus::Active->value);
        assertSameValue('INACTIVE', AcknowledgementRequirementStatus::Inactive->value);
        assertSameValue(2, count(AcknowledgementRequirementStatus::cases()));
    });

    $runner->add('Completion factory builds one new immutable child per persisted active requirement', function (): void {
        $completedAt = new DateTimeImmutable('2026-08-14 15:16:17.123456+00:00');
        $completion = RepresentativeAcknowledgementCompletion::complete(
            new RepresentativeId(20),
            new AcademicPeriodId(10),
            $completedAt,
            [persistedInstitutionalRequirement(1, 10), persistedInstitutionalRequirement(2, 10)],
        );
        $acknowledgements = $completion->acknowledgements();

        assertSameValue(null, $completion->id());
        assertSameValue(20, $completion->representativeId()->value());
        assertSameValue(10, $completion->academicPeriodId()->value());
        assertSameValue(true, $completion->completedAt() === $completedAt);
        assertSameValue(2, count($acknowledgements));
        assertSameValue(null, $acknowledgements[0]->id());
        assertSameValue(1, $acknowledgements[0]->acknowledgementRequirementId()->value());
        assertSameValue(null, $acknowledgements[1]->id());
        assertSameValue(2, $acknowledgements[1]->acknowledgementRequirementId()->value());
        assertSameValue(true, (new ReflectionClass($acknowledgements[0]))->isReadOnly());
    });

    $runner->add('Completion creation rejects an empty or malformed requirement set', function (): void {
        assertThrows(
            static fn (): RepresentativeAcknowledgementCompletion => RepresentativeAcknowledgementCompletion::complete(
                new RepresentativeId(1),
                new AcademicPeriodId(10),
                new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
                [],
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn (): RepresentativeAcknowledgementCompletion => RepresentativeAcknowledgementCompletion::complete(
                new RepresentativeId(1),
                new AcademicPeriodId(10),
                new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
                ['not a requirement'],
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Completion creation requires persisted active requirements from its period', function (): void {
        $arguments = [new RepresentativeId(1), new AcademicPeriodId(10), new DateTimeImmutable('2026-08-14T00:00:00+00:00')];

        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletion::complete(...[...$arguments, [newInstitutionalRequirement()]]),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletion::complete(...[
                ...$arguments,
                [persistedInstitutionalRequirement(1, 10, AcknowledgementRequirementStatus::Inactive)],
            ]),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletion::complete(...[
                ...$arguments,
                [persistedInstitutionalRequirement(1, 11)],
            ]),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Completion creation rejects duplicate requirement identities', function (): void {
        assertThrows(
            static fn (): RepresentativeAcknowledgementCompletion => RepresentativeAcknowledgementCompletion::complete(
                new RepresentativeId(1),
                new AcademicPeriodId(10),
                new DateTimeImmutable('2026-08-14T00:00:00+00:00'),
                [persistedInstitutionalRequirement(1, 10), persistedInstitutionalRequirement(1, 10)],
            ),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Completion reconstitution preserves persisted historical facts without current requirements', function (): void {
        $completedAt = new DateTimeImmutable('2026-08-14T10:11:12+00:00');
        $completion = RepresentativeAcknowledgementCompletion::reconstitute(
            new RepresentativeAcknowledgementCompletionId(30),
            new RepresentativeId(20),
            new AcademicPeriodId(10),
            $completedAt,
            [
                RepresentativeAcknowledgement::reconstitute(
                    new RepresentativeAcknowledgementId(40),
                    new AcknowledgementRequirementId(1),
                ),
                RepresentativeAcknowledgement::reconstitute(
                    new RepresentativeAcknowledgementId(41),
                    new AcknowledgementRequirementId(2),
                ),
            ],
        );

        assertSameValue(30, $completion->id()?->value());
        assertSameValue(true, $completion->completedAt() === $completedAt);
        assertSameValue(40, $completion->acknowledgements()[0]->id()?->value());
        assertSameValue(41, $completion->acknowledgements()[1]->id()?->value());
    });

    $runner->add('Completion reconstitution rejects empty invalid and unpersisted children', function (): void {
        $base = [
            new RepresentativeAcknowledgementCompletionId(30),
            new RepresentativeId(20),
            new AcademicPeriodId(10),
            new DateTimeImmutable('2026-08-14T10:11:12+00:00'),
        ];

        foreach ([[], ['invalid'], [RepresentativeAcknowledgement::create(new AcknowledgementRequirementId(1))]] as $children) {
            assertThrows(
                static fn () => RepresentativeAcknowledgementCompletion::reconstitute(...[...$base, $children]),
                InvalidInstitutionalAcknowledgementState::class
            );
        }
    });

    $runner->add('Completion reconstitution rejects duplicate child and requirement identities', function (): void {
        $base = [
            new RepresentativeAcknowledgementCompletionId(30),
            new RepresentativeId(20),
            new AcademicPeriodId(10),
            new DateTimeImmutable('2026-08-14T10:11:12+00:00'),
        ];
        $first = RepresentativeAcknowledgement::reconstitute(
            new RepresentativeAcknowledgementId(40),
            new AcknowledgementRequirementId(1),
        );

        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletion::reconstitute(...[
                ...$base,
                [$first, RepresentativeAcknowledgement::reconstitute(
                    new RepresentativeAcknowledgementId(40),
                    new AcknowledgementRequirementId(2),
                )],
            ]),
            InvalidInstitutionalAcknowledgementState::class
        );
        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletion::reconstitute(...[
                ...$base,
                [$first, RepresentativeAcknowledgement::reconstitute(
                    new RepresentativeAcknowledgementId(41),
                    new AcknowledgementRequirementId(1),
                )],
            ]),
            InvalidInstitutionalAcknowledgementState::class
        );
    });

    $runner->add('Completion exposes an encapsulated immutable historical collection', function (): void {
        $completion = RepresentativeAcknowledgementCompletion::complete(
            new RepresentativeId(20),
            new AcademicPeriodId(10),
            new DateTimeImmutable('2026-08-14T10:11:12+00:00'),
            [persistedInstitutionalRequirement(1, 10)],
        );
        $copy = $completion->acknowledgements();
        $copy[] = RepresentativeAcknowledgement::create(new AcknowledgementRequirementId(2));
        $reflection = new ReflectionClass($completion);

        assertSameValue(1, count($completion->acknowledgements()));
        foreach (['id', 'representativeId', 'academicPeriodId', 'completedAt', 'acknowledgements'] as $property) {
            assertSameValue(true, $reflection->getProperty($property)->isReadOnly());
        }
        foreach (['addAcknowledgement', 'removeAcknowledgement', 'expire', 'recalculate', 'reconfirm'] as $method) {
            assertSameValue(false, method_exists($completion, $method));
        }
    });

    $runner->add('Institutional Acknowledgements Domain stays within the approved technical boundary', function (): void {
        $directory = __DIR__ . '/../app/InstitutionalDocuments/Domain';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];
        $source = '';

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        sort($files, SORT_STRING);
        assertSameValue(13, count($files));
        foreach (['App\\Representative', 'App\\Family', 'App\\Student', 'App\\Enrollment', 'PDO', 'Repository', 'Service', 'Controller', 'Http'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        foreach (['Application', 'Infrastructure', 'Persistence', 'Delivery'] as $forbiddenDirectory) {
            assertSameValue(false, is_dir(__DIR__ . '/../app/InstitutionalDocuments/' . $forbiddenDirectory));
        }
    });
}

function newInstitutionalRequirement(): AcknowledgementRequirement
{
    return AcknowledgementRequirement::create(
        new AcademicPeriodId(10),
        new AcknowledgementRequirementTitle('Family handbook'),
        new AcknowledgementRequirementUrl('resources/handbook'),
        null,
        AcknowledgementRequirementStatus::Active,
    );
}

function persistedInstitutionalRequirement(
    int $id,
    int $academicPeriodId,
    AcknowledgementRequirementStatus $status = AcknowledgementRequirementStatus::Active,
): AcknowledgementRequirement {
    return AcknowledgementRequirement::reconstitute(
        new AcknowledgementRequirementId($id),
        new AcademicPeriodId($academicPeriodId),
        new AcknowledgementRequirementTitle('Requirement ' . $id),
        new AcknowledgementRequirementUrl('reference/' . $id),
        new AcknowledgementOfficialReference('OFFICIAL-' . $id),
        $status,
    );
}
