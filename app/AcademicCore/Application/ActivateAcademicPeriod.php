<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\AcademicCore\Application\Exception\InvalidPersistedAcademicPeriodResult;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use Core\Application\TransactionRunner;

final readonly class ActivateAcademicPeriod
{
    public function __construct(
        private AcademicPeriodRepository $periods,
        private TransactionRunner $transactions,
    ) {
    }

    public function handle(int $academicPeriodId): AcademicPeriodOutput
    {
        return $this->transactions->run(function () use ($academicPeriodId): AcademicPeriodOutput {
            $this->periods->lockOperationalTransition();
            $target = AcademicPeriodApplicationSupport::load(
                $this->periods,
                new AcademicPeriodId($academicPeriodId),
            );
            $current = $this->periods->findActive();

            if ($target->isActive()) {
                if ($current === null || !$current->id()?->equals($target->id())) {
                    throw new InvalidPersistedAcademicPeriodResult(
                        'ACTIVE AcademicPeriod was not resolved as the operational period.'
                    );
                }

                return AcademicPeriodOutput::fromAcademicPeriod($target);
            }

            if ($current !== null) {
                $current->deactivate();
                AcademicPeriodApplicationSupport::save($this->periods, $current);
            }

            $target->activate();
            $output = AcademicPeriodApplicationSupport::save($this->periods, $target);
            $active = $this->periods->findActive();
            if ($active === null || !$active->id()?->equals($target->id())) {
                throw new InvalidPersistedAcademicPeriodResult(
                    'AcademicPeriod activation could not be confirmed.'
                );
            }

            return $output;
        });
    }
}
