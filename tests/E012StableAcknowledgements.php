<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSubmissionSatisfaction;

final readonly class E012StableAcknowledgements implements InstitutionalAcknowledgementSubmissionSatisfaction
{
    public function __construct(
        private bool $satisfied,
        private E012SubmissionTrace $trace,
    ) {
    }

    public function isSatisfiedInStableContext(int $representativeId, int $academicPeriodId): bool
    {
        $this->trace->events[] = 'ack-lock';

        return $this->satisfied;
    }
}
