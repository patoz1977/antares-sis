<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingContext;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;
use InvalidArgumentException;

final readonly class GetPhysicalDepartureReport
{
    public function __construct(private PhysicalDepartureReportQuery $query)
    {
    }

    /** @return list<PhysicalDepartureReportRow> */
    public function handle(EnrollmentReportingContext $context): array
    {
        if ($context->academicPeriod->status !== AcademicPeriodStatus::Active) {
            throw new InvalidArgumentException('Physical departure reporting requires the ACTIVE AcademicPeriod.');
        }
        $context->gradeSectionFilter->assertAcademicPeriod($context->academicPeriod->id);

        return $this->query->fetch($context->academicPeriod->id, $context->gradeSectionFilter);
    }
}
