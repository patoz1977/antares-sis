<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Support;

use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentPersistedStateMismatch;
use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;

final class RepresentativePersistenceSupport
{
    public static function save(
        RepresentativeRepository $representatives,
        Representative $expected,
    ): RepresentativeOutput {
        $persisted = $representatives->save($expected);
        $id = $persisted->id();
        $expectedId = $expected->id();
        if ($id === null
            || $expectedId === null
            || !$id->equals($expectedId)
            || !$persisted->personId()->equals($expected->personId())
            || !self::optionalEquals(
                $persisted->employmentInformation(),
                $expected->employmentInformation(),
            )
            || $persisted->status() !== $expected->status()
        ) {
            throw new RepresentativeEnrollmentPersistedStateMismatch(
                'Representative Enrollment role persistence returned incoherent state.'
            );
        }

        return RepresentativeOutput::fromRepresentative($persisted, $id);
    }

    private static function optionalEquals(?object $left, ?object $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private function __construct()
    {
    }
}
