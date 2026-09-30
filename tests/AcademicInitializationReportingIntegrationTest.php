<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Infrastructure\Reporting\PdoStudentEnrollmentListQuery;
use Tests\Support\TestRunner;

function registerAcademicInitializationReportingIntegrationTests(TestRunner $runner): void
{
    $runner->add('Post-import academic initialization becomes visible in E013 without changing unrelated Students', function (): void {
        [$manager, $pdo] = enrollmentReportingFixture();
        $query = new PdoStudentEnrollmentListQuery($manager);

        $before = $query->fetch(101);
        $targetBefore = array_values(array_filter($before, static fn ($row): bool => $row->studentId === 1))[0];
        $unrelatedBefore = array_values(array_filter($before, static fn ($row): bool => $row->studentId === 2))[0];
        assertSameValue(['NOT STARTED', null, null], [
            $targetBefore->status->value,
            $targetBefore->gradeName,
            $targetBefore->sectionName,
        ]);

        $pdo->exec("INSERT INTO enrollments (id, student_id, family_id, academic_period_id, status_id, grade_id, section_id) VALUES (1098, 1, 500, 101, 11, 1, 1)");

        $after = $query->fetch(101);
        $targetAfter = array_values(array_filter($after, static fn ($row): bool => $row->studentId === 1))[0];
        $unrelatedAfter = array_values(array_filter($after, static fn ($row): bool => $row->studentId === 2))[0];
        assertSameValue(['DRAFT', 'Grade One', 'A'], [
            $targetAfter->status->value,
            $targetAfter->gradeName,
            $targetAfter->sectionName,
        ]);
        assertSameValue(
            [$unrelatedBefore->status->value, $unrelatedBefore->gradeName, $unrelatedBefore->sectionName],
            [$unrelatedAfter->status->value, $unrelatedAfter->gradeName, $unrelatedAfter->sectionName],
        );
        assertSameValue(count($before), count($after));
    });
}
