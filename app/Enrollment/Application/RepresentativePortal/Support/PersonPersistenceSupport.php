<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Support;

use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentPersistedStateMismatch;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use DateTimeZone;

final class PersonPersistenceSupport
{
    public static function save(PersonRepository $persons, Person $expected): PersonOutput
    {
        $persisted = $persons->save($expected);
        if (!self::sameState($persisted, $expected)) {
            throw new RepresentativeEnrollmentPersistedStateMismatch(
                'Representative Enrollment Person persistence returned incoherent state.'
            );
        }

        $id = $persisted->id();
        if ($id === null) {
            throw new RepresentativeEnrollmentPersistedStateMismatch(
                'Representative Enrollment Person does not have persisted identity.'
            );
        }

        return PersonOutput::fromPerson($persisted, $id);
    }

    private static function sameState(Person $left, Person $right): bool
    {
        $leftId = $left->id();
        $rightId = $right->id();

        return $leftId !== null
            && $rightId !== null
            && $leftId->equals($rightId)
            && $left->personalName()->equals($right->personalName())
            && self::optionalEquals($left->identification(), $right->identification())
            && $left->birthDate()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
                === $right->birthDate()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
            && $left->sexId() === $right->sexId()
            && $left->maritalStatusId() === $right->maritalStatusId()
            && $left->educationLevelId() === $right->educationLevelId()
            && self::optionalEquals($left->contactInformation(), $right->contactInformation())
            && $left->status() === $right->status();
    }

    private static function optionalEquals(?object $left, ?object $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function __construct()
    {
    }
}
