<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Controllers\Controller;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetEnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\GetStudentBillingReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\GetStudentMedicalReport;
use App\Enrollment\Application\Reporting\GetStudentRepresentativeDirectory;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Shared\Http\SafeErrorPage;
use Core\Http\Request;
use InvalidArgumentException;
use Throwable;

final class EnrollmentReportingController extends Controller
{
    public function __construct(
        private readonly GetEnrollmentReportingPeriods $getPeriods,
        private readonly ResolveEnrollmentReportingPeriod $resolvePeriod,
        private readonly GetEnrollmentSummaryReport $getSummary,
        private readonly GetStudentEnrollmentReport $getStudents,
        private readonly GetStudentRepresentativeDirectory $getDirectory,
        private readonly GetStudentBillingReport $getBilling,
        private readonly GetStudentMedicalReport $getMedical,
        private readonly EnrollmentReportCsvWriter $csv,
    ) {
    }

    public function index(): string
    {
        try {
            [$periods, $selected] = $this->reportingContext();
            $this->noStore();

            return $this->view('reports.enrollments.index', $this->viewData(
                'Basic Enrollment Reports',
                '/reports/enrollments',
                $periods,
                $selected,
            ));
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    public function summary(): string
    {
        return $this->htmlReport(
            'Enrollment Summary',
            '/reports/enrollments/summary',
            'reports.enrollments.summary',
            fn (int $periodId): mixed => $this->getSummary->handle($periodId),
        );
    }

    public function students(): string
    {
        return $this->htmlReport(
            'Student Enrollment List',
            '/reports/enrollments/students',
            'reports.enrollments.students',
            fn (int $periodId): mixed => $this->getStudents->handle($periodId),
        );
    }

    public function directory(): string
    {
        return $this->htmlReport(
            'Student and Representative Directory',
            '/reports/enrollments/directory',
            'reports.enrollments.directory',
            fn (int $periodId): mixed => $this->getDirectory->handle($periodId),
        );
    }

    public function billing(): string
    {
        return $this->htmlReport(
            'Student Billing Information',
            '/reports/enrollments/billing',
            'reports.enrollments.billing',
            fn (int $periodId): mixed => $this->getBilling->handle($periodId),
        );
    }

    public function medical(): string
    {
        return $this->htmlReport(
            'Student Medical Information',
            '/reports/enrollments/medical',
            'reports.enrollments.medical',
            fn (int $periodId): mixed => $this->getMedical->handle($periodId),
        );
    }

    public function summaryCsv(): string
    {
        return $this->csvReport(
            'enrollment-summary-period-',
            fn (int $periodId): mixed => $this->getSummary->handle($periodId),
            fn (mixed $dataset): string => $this->csv->summary($dataset),
        );
    }

    public function studentsCsv(): string
    {
        return $this->csvReport(
            'student-enrollment-period-',
            fn (int $periodId): mixed => $this->getStudents->handle($periodId),
            fn (mixed $dataset): string => $this->csv->students($dataset),
        );
    }

    public function directoryCsv(): string
    {
        return $this->csvReport(
            'student-directory-period-',
            fn (int $periodId): mixed => $this->getDirectory->handle($periodId),
            fn (mixed $dataset): string => $this->csv->directory($dataset),
        );
    }

    public function billingCsv(): string
    {
        return $this->csvReport(
            'student-billing-period-',
            fn (int $periodId): mixed => $this->getBilling->handle($periodId),
            fn (mixed $dataset): string => $this->csv->billing($dataset),
        );
    }

    public function medicalCsv(): string
    {
        return $this->csvReport(
            'student-medical-period-',
            fn (int $periodId): mixed => $this->getMedical->handle($periodId),
            fn (mixed $dataset): string => $this->csv->medical($dataset),
        );
    }

    /** @param callable(int): mixed $load */
    private function htmlReport(string $title, string $path, string $view, callable $load): string
    {
        try {
            [$periods, $selected] = $this->reportingContext();
            $dataset = $selected === null ? null : $load($selected->id);
            $this->noStore();

            return $this->view($view, $this->viewData($title, $path, $periods, $selected) + [
                'dataset' => $dataset,
            ]);
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    /** @param callable(int): mixed $load @param callable(mixed): string $write */
    private function csvReport(string $filenamePrefix, callable $load, callable $write): string
    {
        try {
            [, $selected] = $this->reportingContext();
            if ($selected === null) {
                throw new InvalidArgumentException('An AcademicPeriod selection is required.');
            }

            $dataset = $load($selected->id);
            $content = $write($dataset);
            $filename = $filenamePrefix . $selected->id . '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $this->noStore();
            http_response_code(200);

            return $content;
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    /** @return array{EnrollmentReportingPeriods, ?ReportingAcademicPeriod} */
    private function reportingContext(): array
    {
        $query = (new Request())->query();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        if ($keys !== [] && $keys !== ['academic_period_id']) {
            throw new InvalidArgumentException('Invalid reporting selector.');
        }

        $periods = $this->getPeriods->handle();
        $periodId = $periods->defaultAcademicPeriodId;
        if (array_key_exists('academic_period_id', $query)) {
            $periodId = $this->positiveInteger($query['academic_period_id']);
            if ($periodId === null) {
                throw new InvalidArgumentException('Invalid AcademicPeriod selection.');
            }
        }

        return [$periods, $periodId === null ? null : $this->resolvePeriod->handle($periodId)];
    }

    /** @return array<string, mixed> */
    private function viewData(
        string $title,
        string $path,
        EnrollmentReportingPeriods $periods,
        ?ReportingAcademicPeriod $selected,
    ): array {
        $periodQuery = $selected === null ? '' : '?academic_period_id=' . $selected->id;

        return [
            'title' => $title,
            'reportPath' => $path,
            'periods' => $periods->periods,
            'selectedPeriod' => $selected,
            'selectedPeriodId' => $selected?->id,
            'selectionRequired' => $selected === null,
            'periodQuery' => $periodQuery,
        ];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }

    private function safeError(Throwable $exception): string
    {
        $status = match (true) {
            $exception instanceof InvalidArgumentException => 400,
            $exception instanceof EnrollmentReportingPeriodNotFound => 404,
            default => 500,
        };
        $message = match ($status) {
            400 => 'Seleccione un período académico válido.',
            404 => 'El período académico no está disponible.',
            default => 'No se pudo generar el reporte.',
        };

        $this->noStore();
        http_response_code($status);

        return SafeErrorPage::render($status, $message, '/reports/enrollments', 'Volver a reportes de matrícula');
    }

    private function noStore(): void
    {
        header('Cache-Control: no-store');
    }
}
