<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\StudentEnrollmentListQuery;
use RuntimeException;

final class PdoStudentEnrollmentListQuery extends PdoEnrollmentReportingQuery implements StudentEnrollmentListQuery
{
    public function fetch(int $academicPeriodId): array
    {
        $this->positiveInt($academicPeriodId, 'AcademicPeriod identity');
        $rows = $this->rows(
            'SELECT s.id AS student_id, student_status_type.code AS student_status_type, '
            . 'student_status.code AS student_status_code, '
            . 'p.first_name AS student_first_name, p.middle_name AS student_middle_name, '
            . 'p.first_surname AS student_first_surname, p.second_surname AS student_second_surname, '
            . 'e.id AS enrollment_id, enrollment_status_type.code AS enrollment_status_type, '
            . 'enrollment_status.code AS enrollment_status_code, '
            . 'g.id AS grade_id, g.name AS grade_name, g.sort_order AS grade_sort_order, '
            . 'sec.id AS section_id, sec.name AS section_name '
            . 'FROM students s '
            . 'INNER JOIN persons p ON p.id = s.person_id '
            . 'INNER JOIN statuses student_status ON student_status.id = s.status_id '
            . 'INNER JOIN status_types student_status_type '
            . 'ON student_status_type.id = student_status.status_type_id '
            . 'LEFT JOIN enrollments e ON e.student_id = s.id AND e.academic_period_id = :academicPeriodId '
            . 'LEFT JOIN statuses enrollment_status ON enrollment_status.id = e.status_id '
            . 'LEFT JOIN status_types enrollment_status_type '
            . 'ON enrollment_status_type.id = enrollment_status.status_type_id '
            . 'LEFT JOIN grades g ON g.id = e.grade_id '
            . 'LEFT JOIN sections sec ON sec.id = e.section_id '
            . 'ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END, g.sort_order, g.id, '
            . 'CASE WHEN sec.id IS NULL THEN 1 ELSE 0 END, sec.name, sec.id, '
            . 'p.first_surname, p.second_surname, p.first_name, p.middle_name, s.id',
            [':academicPeriodId' => $academicPeriodId],
        );

        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'Student identity');
            if (isset($seen[$studentId])) {
                throw new RuntimeException('Student Enrollment report returned duplicate Student rows.');
            }
            $seen[$studentId] = true;
            $active = $this->isActiveStudent($row);
            $status = $this->reportingStatus($row);
            [$gradeId, $gradeName, $sectionId, $sectionName] = $this->placement($row);
            [$surnames, $names] = $this->studentName($row);
            if (!$active) {
                continue;
            }

            $result[] = new StudentEnrollmentReportRow(
                $studentId,
                $gradeId,
                $gradeName,
                $sectionId,
                $sectionName,
                $surnames,
                $names,
                $status,
            );
        }

        return $result;
    }
}
