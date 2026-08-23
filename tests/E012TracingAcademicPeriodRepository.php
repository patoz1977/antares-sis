<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;

final readonly class E012TracingAcademicPeriodRepository implements AcademicPeriodRepository
{
    public function __construct(
        private AcademicPeriodRepository $delegate,
        private E012SubmissionTrace $trace,
    ) {
    }

    public function findById(AcademicPeriodId $id): ?AcademicPeriod
    {
        return $this->delegate->findById($id);
    }

    public function findActive(): ?AcademicPeriod
    {
        return $this->delegate->findActive();
    }

    public function save(AcademicPeriod $period): AcademicPeriod
    {
        return $this->delegate->save($period);
    }

    public function lockOperationalTransition(): void
    {
        $this->delegate->lockOperationalTransition();
    }

    public function lockActiveContextForRead(): void
    {
        $this->trace->events[] = 'status-lock';
        $this->delegate->lockActiveContextForRead();
    }
}
