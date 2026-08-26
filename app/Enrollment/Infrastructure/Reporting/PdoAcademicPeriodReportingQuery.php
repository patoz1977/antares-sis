<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Reporting\AcademicPeriodReportingQuery;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use RuntimeException;

final class PdoAcademicPeriodReportingQuery extends PdoEnrollmentReportingQuery implements AcademicPeriodReportingQuery
{
    public function findAll(): array
    {
        $rows = $this->rows(
            'SELECT ap.id, ap.code, ap.name, ap.starts_on, ap.ends_on, '
            . 'status_type.code AS status_type_code, status_row.code AS status_code '
            . 'FROM academic_periods ap '
            . 'INNER JOIN statuses status_row ON status_row.id = ap.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
            . 'ORDER BY ap.id ASC'
        );

        $periods = [];
        foreach ($rows as $row) {
            $id = $this->positiveInt($row['id'] ?? null, 'AcademicPeriod identity');
            if (isset($periods[$id])) {
                throw new RuntimeException('Reporting AcademicPeriod query returned a duplicate identity.');
            }
            if (($row['status_type_code'] ?? null) !== self::STUDENT_STATUS_TYPE) {
                throw new RuntimeException('Reporting AcademicPeriod status does not belong to GENERAL_STATUS.');
            }
            $statusCode = $row['status_code'] ?? null;
            $status = is_string($statusCode) ? AcademicPeriodStatus::tryFrom($statusCode) : null;
            if ($status === null) {
                throw new RuntimeException('Reporting AcademicPeriod has an unsupported GENERAL_STATUS value.');
            }

            $periods[$id] = new ReportingAcademicPeriod(
                $id,
                $this->requiredString($row['code'] ?? null, 'AcademicPeriod code'),
                $this->requiredString($row['name'] ?? null, 'AcademicPeriod name'),
                $this->date($row['starts_on'] ?? null, 'AcademicPeriod start date'),
                $this->date($row['ends_on'] ?? null, 'AcademicPeriod end date'),
                $status,
            );
        }

        return array_values($periods);
    }
}
