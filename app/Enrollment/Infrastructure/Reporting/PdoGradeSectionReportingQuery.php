<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\ReportingGradeSectionOption;
use App\Enrollment\Application\Reporting\GradeSectionReportingQuery;
use RuntimeException;

final class PdoGradeSectionReportingQuery extends PdoEnrollmentReportingQuery implements GradeSectionReportingQuery
{
    public function findForAcademicPeriod(int $academicPeriodId): array
    {
        $this->positiveInt($academicPeriodId, 'AcademicPeriod identity');
        $rows = $this->rows(
            'SELECT DISTINCT g.id AS grade_id, g.code AS grade_code, g.name AS grade_name, '
            . 'g.sort_order AS grade_sort_order, sec.id AS section_id, sec.code AS section_code, '
            . 'sec.name AS section_name '
            . 'FROM enrollments e '
            . 'INNER JOIN grades g ON g.id = e.grade_id '
            . 'INNER JOIN sections sec ON sec.id = e.section_id '
            . 'WHERE e.academic_period_id = :academicPeriodId '
            . 'ORDER BY g.sort_order, g.id, sec.name, sec.id',
            [':academicPeriodId' => $academicPeriodId],
        );

        $result = [];
        foreach ($rows as $row) {
            $option = new ReportingGradeSectionOption(
                $this->positiveInt($row['grade_id'] ?? null, 'Grade identity'),
                $this->requiredString($row['grade_code'] ?? null, 'Grade code'),
                $this->requiredString($row['grade_name'] ?? null, 'Grade name'),
                $this->positiveInt($row['grade_sort_order'] ?? null, 'Grade sort order'),
                $this->positiveInt($row['section_id'] ?? null, 'Section identity'),
                $this->requiredString($row['section_code'] ?? null, 'Section code'),
                $this->requiredString($row['section_name'] ?? null, 'Section name'),
            );
            if (isset($result[$option->key()])) {
                throw new RuntimeException('Reporting Grade/Section query returned a duplicate option.');
            }
            $result[$option->key()] = $option;
        }

        return array_values($result);
    }
}
