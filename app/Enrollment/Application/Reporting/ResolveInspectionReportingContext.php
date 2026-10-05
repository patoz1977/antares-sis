<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingContext;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;
use InvalidArgumentException;
use RuntimeException;

final readonly class ResolveInspectionReportingContext
{
    public function __construct(
        private GetActiveAcademicPeriod $getActiveAcademicPeriod,
        private ResolveEnrollmentReportingContext $resolveReportingContext,
    ) {
    }

    public function handle(mixed $requestedAcademicPeriodId, mixed $requestedGradeSections): EnrollmentReportingContext
    {
        $active = $this->getActiveAcademicPeriod->handle();
        if ($active === null) {
            throw new EnrollmentReportingPeriodNotFound('An ACTIVE AcademicPeriod is required.');
        }
        if ($requestedAcademicPeriodId !== null
            && $this->positiveInteger($requestedAcademicPeriodId) !== $active->id
        ) {
            throw new InvalidArgumentException('The requested AcademicPeriod is not the ACTIVE AcademicPeriod.');
        }

        $context = $this->resolveReportingContext->handle($active->id, $requestedGradeSections);
        if ($context->academicPeriod->status !== AcademicPeriodStatus::Active) {
            throw new RuntimeException('The operational reporting context is not ACTIVE.');
        }

        return $context;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }
}
