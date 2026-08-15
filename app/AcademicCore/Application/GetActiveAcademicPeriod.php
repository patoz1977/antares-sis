<?php

declare(strict_types=1);

namespace App\AcademicCore\Application;

use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\AcademicCore\Domain\AcademicPeriodRepository;

final readonly class GetActiveAcademicPeriod
{
    public function __construct(private AcademicPeriodRepository $periods)
    {
    }

    public function handle(): ?AcademicPeriodOutput
    {
        $period = $this->periods->findActive();

        return $period === null ? null : AcademicPeriodOutput::fromAcademicPeriod($period);
    }
}
