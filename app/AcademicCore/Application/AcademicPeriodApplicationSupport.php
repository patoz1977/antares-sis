<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\AcademicCore\Application\Exception\AcademicPeriodNotFound;
use App\AcademicCore\Application\Exception\InvalidPersistedAcademicPeriodResult;
use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;

final class AcademicPeriodApplicationSupport
{
    public static function load(AcademicPeriodRepository $periods, AcademicPeriodId $id): AcademicPeriod
    {
        $period = $periods->findById($id);
        if ($period === null) {
            throw new AcademicPeriodNotFound('AcademicPeriod was not found.');
        }

        return $period;
    }

    public static function save(
        AcademicPeriodRepository $periods,
        AcademicPeriod $expected,
    ): AcademicPeriodOutput {
        $persisted = $periods->save($expected);
        if (!self::sameState($persisted, $expected)) {
            throw new InvalidPersistedAcademicPeriodResult(
                'AcademicPeriod persistence returned incoherent state.'
            );
        }

        return AcademicPeriodOutput::fromAcademicPeriod($persisted);
    }

    private static function sameState(AcademicPeriod $left, AcademicPeriod $right): bool
    {
        $leftId = $left->id();
        $rightId = $right->id();

        return $leftId !== null
            && $rightId !== null
            && $leftId->equals($rightId)
            && $left->code()->equals($right->code())
            && $left->name()->equals($right->name())
            && $left->dates()->equals($right->dates())
            && $left->status() === $right->status();
    }
}
