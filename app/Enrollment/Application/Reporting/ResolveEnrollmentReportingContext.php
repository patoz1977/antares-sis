<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingContext;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingSelectionInvalid;
use RuntimeException;

final readonly class ResolveEnrollmentReportingContext
{
    public function __construct(
        private ResolveEnrollmentReportingPeriod $resolvePeriod,
        private GradeSectionReportingQuery $gradeSections,
    ) {
    }

    public function handle(int $academicPeriodId, mixed $requestedGradeSections): EnrollmentReportingContext
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);
        $options = $this->gradeSections->findForAcademicPeriod($period->id);
        $available = [];
        foreach ($options as $option) {
            $key = $option->key();
            if (isset($available[$key])) {
                throw new RuntimeException('Reporting Grade/Section options contain a duplicate key.');
            }
            $available[$key] = $option;
        }

        if ($requestedGradeSections === null || $requestedGradeSections === []) {
            $filter = ReportingGradeSectionFilter::all($period->id);
        } else {
            if (!is_array($requestedGradeSections)) {
                throw new EnrollmentReportingSelectionInvalid('Grade/Section selection must be a list.');
            }
            $selected = [];
            foreach ($requestedGradeSections as $requested) {
                if (!is_string($requested)
                    || $requested === ''
                    || trim($requested) !== $requested
                    || substr_count($requested, ':') !== 1
                    || !isset($available[$requested])
                ) {
                    throw new EnrollmentReportingSelectionInvalid(
                        'Grade/Section selection is malformed or unavailable for the AcademicPeriod.'
                    );
                }
                $selected[$requested] = $available[$requested];
            }
            if ($selected === []) {
                throw new EnrollmentReportingSelectionInvalid('Grade/Section selection is empty.');
            }
            $filter = ReportingGradeSectionFilter::selected($period->id, array_values($selected));
        }

        return new EnrollmentReportingContext($period, $options, $filter);
    }
}
