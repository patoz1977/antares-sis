<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryRow;
use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Application\Reporting\EnrollmentSummaryQuery;
use RuntimeException;

final class PdoEnrollmentSummaryQuery extends PdoEnrollmentReportingQuery implements EnrollmentSummaryQuery
{
    public function fetch(int $academicPeriodId): array
    {
        $this->positiveInt($academicPeriodId, 'AcademicPeriod identity');
        $rows = $this->rows(
            'SELECT status_type.code AS enrollment_status_type, '
            . 'status_row.code AS enrollment_status_code, status_row.sort_order AS status_sort_order, '
            . 'g.id AS grade_id, g.name AS grade_name, g.sort_order AS grade_sort_order, '
            . 'sec.id AS section_id, sec.name AS section_name, COUNT(*) AS enrollment_count '
            . 'FROM enrollments e '
            . 'INNER JOIN statuses status_row ON status_row.id = e.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
            . 'LEFT JOIN grades g ON g.id = e.grade_id '
            . 'LEFT JOIN sections sec ON sec.id = e.section_id '
            . 'WHERE e.academic_period_id = :academicPeriodId '
            . 'GROUP BY status_type.code, status_row.code, status_row.sort_order, status_row.id, '
            . 'g.id, g.name, g.sort_order, sec.id, sec.name '
            . 'ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END, g.sort_order, g.id, '
            . 'CASE WHEN sec.id IS NULL THEN 1 ELSE 0 END, sec.name, sec.id, '
            . 'status_row.sort_order, status_row.id',
            [':academicPeriodId' => $academicPeriodId],
        );

        $result = [];
        foreach ($rows as $row) {
            if (($row['enrollment_status_type'] ?? null) !== self::ENROLLMENT_STATUS_TYPE) {
                throw new RuntimeException('Summary Enrollment status does not belong to ENROLLMENT_STATUS.');
            }
            $statusCode = $row['enrollment_status_code'] ?? null;
            if (!is_string($statusCode)) {
                throw new RuntimeException('Summary Enrollment status is invalid.');
            }
            $status = EnrollmentReportingStatus::fromEnrollmentCode($statusCode);
            [$gradeId, $gradeName, $sectionId, $sectionName] = $this->placement($row);
            $count = $this->positiveInt($row['enrollment_count'] ?? null, 'Enrollment summary count');

            $result[] = new EnrollmentSummaryRow(
                $status,
                $gradeId,
                $gradeName,
                $sectionId,
                $sectionName,
                $count,
            );
        }

        return $result;
    }
}
