<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Controllers\Controller;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingContext;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingSelectionInvalid;
use App\Enrollment\Application\Reporting\Exception\ReportingStudentStatusSelectionInvalid;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetEnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\GetPhysicalDepartureReport;
use App\Enrollment\Application\Reporting\GetStudentBillingReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\GetStudentMedicalReport;
use App\Enrollment\Application\Reporting\GetStudentRepresentativeDirectory;
use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;
use App\Enrollment\Application\Reporting\ReportingStudentStatusFilter;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingContext;
use App\Enrollment\Application\Reporting\ResolveInspectionReportingContext;
use App\Shared\Http\SafeErrorPage;
use Core\Http\Request;
use InvalidArgumentException;
use Throwable;

final class EnrollmentReportingController extends Controller
{
    public function __construct(
        private readonly GetEnrollmentReportingPeriods $getPeriods,
        private readonly ResolveEnrollmentReportingContext $resolveContext,
        private readonly GetEnrollmentSummaryReport $getSummary,
        private readonly GetStudentEnrollmentReport $getStudents,
        private readonly GetStudentRepresentativeDirectory $getDirectory,
        private readonly GetStudentBillingReport $getBilling,
        private readonly GetStudentMedicalReport $getMedical,
        private readonly ResolveInspectionReportingContext $resolveInspectionContext,
        private readonly GetPhysicalDepartureReport $getPhysicalDeparture,
        private readonly EnrollmentReportCsvWriter $csv,
    ) {
    }

    public function index(): string
    {
        try {
            [$periods, $context] = $this->reportingContext(ReportingStudentStatusFilter::Active, false);
            $this->noStore();

            return $this->view('reports.enrollments.index', $this->viewData(
                'Basic Enrollment Reports',
                '/reports/enrollments',
                $periods,
                $context,
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
            ReportingStudentStatusFilter::All,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getSummary->handle($periodId, $filter, $status),
        );
    }

    public function students(): string
    {
        return $this->htmlReport(
            'Student Enrollment List',
            '/reports/enrollments/students',
            'reports.enrollments.students',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getStudents->handle($periodId, $filter, $status),
        );
    }

    public function directory(): string
    {
        return $this->htmlReport(
            'Student and Representative Directory',
            '/reports/enrollments/directory',
            'reports.enrollments.directory',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getDirectory->handle($periodId, $filter, $status),
        );
    }

    public function billing(): string
    {
        return $this->htmlReport(
            'Student Billing Information',
            '/reports/enrollments/billing',
            'reports.enrollments.billing',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getBilling->handle($periodId, $filter, $status),
        );
    }

    public function medical(): string
    {
        return $this->htmlReport(
            'Student Medical Information',
            '/reports/enrollments/medical',
            'reports.enrollments.medical',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getMedical->handle($periodId, $filter, $status),
        );
    }

    public function inspection(): string
    {
        try {
            $context = $this->inspectionContext();
            $dataset = $this->getPhysicalDeparture->handle($context);
            $this->noStore();

            return $this->view('reports.enrollments.inspection', $this->inspectionViewData($context) + [
                'dataset' => $dataset,
            ]);
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    public function summaryCsv(): string
    {
        return $this->csvReport(
            'enrollment-summary-period-',
            ReportingStudentStatusFilter::All,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getSummary->handle($periodId, $filter, $status),
            fn (mixed $dataset): string => $this->csv->summary($dataset),
        );
    }

    public function studentsCsv(): string
    {
        return $this->csvReport(
            'student-enrollment-period-',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getStudents->handle($periodId, $filter, $status),
            fn (mixed $dataset): string => $this->csv->students($dataset),
        );
    }

    public function directoryCsv(): string
    {
        return $this->csvReport(
            'student-directory-period-',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getDirectory->handle($periodId, $filter, $status),
            fn (mixed $dataset): string => $this->csv->directory($dataset),
        );
    }

    public function billingCsv(): string
    {
        return $this->csvReport(
            'student-billing-period-',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getBilling->handle($periodId, $filter, $status),
            fn (mixed $dataset): string => $this->csv->billing($dataset),
        );
    }

    public function medicalCsv(): string
    {
        return $this->csvReport(
            'student-medical-period-',
            ReportingStudentStatusFilter::Active,
            fn (int $periodId, ReportingGradeSectionFilter $filter, ReportingStudentStatusFilter $status): mixed =>
                $this->getMedical->handle($periodId, $filter, $status),
            fn (mixed $dataset): string => $this->csv->medical($dataset),
        );
    }

    public function inspectionCsv(): string
    {
        try {
            $context = $this->inspectionContext();
            $dataset = $this->getPhysicalDeparture->handle($context);
            $content = $this->csv->physicalDeparture($dataset);

            return $this->downloadCsv(
                $content,
                'physical-departure-active-period-' . $context->academicPeriod->id . '.csv',
            );
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    /** @param callable(int, ReportingGradeSectionFilter, ReportingStudentStatusFilter): mixed $load */
    private function htmlReport(
        string $title,
        string $path,
        string $view,
        ReportingStudentStatusFilter $defaultStudentStatus,
        callable $load,
    ): string
    {
        try {
            [$periods, $context, $studentStatusExplicit] = $this->reportingContext($defaultStudentStatus);
            $dataset = $context === null
                ? null
                : $load(
                    $context->academicPeriod->id,
                    $context->gradeSectionFilter,
                    $context->studentStatusFilter,
                );
            $this->noStore();

            return $this->view($view, $this->viewData(
                $title,
                $path,
                $periods,
                $context,
                true,
                $studentStatusExplicit,
            ) + [
                'dataset' => $dataset,
            ]);
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    /** @param callable(int, ReportingGradeSectionFilter, ReportingStudentStatusFilter): mixed $load
     *  @param callable(mixed): string $write
     */
    private function csvReport(
        string $filenamePrefix,
        ReportingStudentStatusFilter $defaultStudentStatus,
        callable $load,
        callable $write,
    ): string
    {
        try {
            [, $context] = $this->reportingContext($defaultStudentStatus);
            if ($context === null) {
                throw new InvalidArgumentException('An AcademicPeriod selection is required.');
            }

            $dataset = $load(
                $context->academicPeriod->id,
                $context->gradeSectionFilter,
                $context->studentStatusFilter,
            );
            $content = $write($dataset);
            $filename = $filenamePrefix . $context->academicPeriod->id . '.csv';

            return $this->downloadCsv($content, $filename);
        } catch (Throwable $exception) {
            return $this->safeError($exception);
        }
    }

    /** @return array{EnrollmentReportingPeriods, ?EnrollmentReportingContext, bool} */
    private function reportingContext(
        ReportingStudentStatusFilter $defaultStudentStatus,
        bool $studentStatusAllowed = true,
    ): array
    {
        $query = (new Request())->query();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        $allowed = ['academic_period_id', 'grade_section'];
        if ($studentStatusAllowed) {
            $allowed[] = 'student_status';
        }
        foreach ($keys as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Invalid reporting selector.');
            }
        }

        $periods = $this->getPeriods->handle();
        $periodId = $periods->defaultAcademicPeriodId;
        if (array_key_exists('academic_period_id', $query)) {
            $periodId = $this->positiveInteger($query['academic_period_id']);
            if ($periodId === null) {
                throw new InvalidArgumentException('Invalid AcademicPeriod selection.');
            }
        }

        $requestedGradeSections = $query['grade_section'] ?? null;
        $studentStatusExplicit = array_key_exists('student_status', $query);
        $studentStatus = $defaultStudentStatus;
        if ($studentStatusExplicit) {
            $requestedStudentStatus = $query['student_status'];
            if (!is_string($requestedStudentStatus)
                || trim($requestedStudentStatus) !== $requestedStudentStatus
            ) {
                throw new ReportingStudentStatusSelectionInvalid('Invalid Student status selection.');
            }
            $studentStatus = ReportingStudentStatusFilter::tryFrom($requestedStudentStatus)
                ?? throw new ReportingStudentStatusSelectionInvalid('Unknown Student status selection.');
        }
        if ($periodId === null) {
            if ($requestedGradeSections !== null) {
                throw new EnrollmentReportingSelectionInvalid(
                    'Grade/Section selection requires an AcademicPeriod.'
                );
            }

            return [$periods, null, $studentStatusExplicit];
        }

        return [
            $periods,
            $this->resolveContext->handle($periodId, $requestedGradeSections, $studentStatus),
            $studentStatusExplicit,
        ];
    }

    private function inspectionContext(): EnrollmentReportingContext
    {
        $query = (new Request())->query();
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            if (!in_array($key, ['academic_period_id', 'grade_section'], true)) {
                throw new InvalidArgumentException('Invalid inspection report selector.');
            }
        }

        return $this->resolveInspectionContext->handle(
            $query['academic_period_id'] ?? null,
            $query['grade_section'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function inspectionViewData(EnrollmentReportingContext $context): array
    {
        $selectedGradeSections = $context->gradeSectionFilter->keys();
        $parameters = $selectedGradeSections === [] ? [] : ['grade_section' => $selectedGradeSections];

        return [
            'title' => 'Salida y retiro de estudiantes',
            'reportPath' => '/reports/enrollments/inspection',
            'periods' => [],
            'selectedPeriod' => $context->academicPeriod,
            'selectedPeriodId' => $context->academicPeriod->id,
            'selectionRequired' => false,
            'periodQuery' => $parameters === [] ? '' : '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            'allGradeSectionsQuery' => '',
            'gradeSectionOptions' => $context->gradeSectionOptions,
            'selectedGradeSections' => $selectedGradeSections,
            'gradeSectionFilterActive' => $selectedGradeSections !== [],
            'inspectionActivePeriodOnly' => true,
            'studentStatusFilterAvailable' => false,
            'selectedStudentStatus' => ReportingStudentStatusFilter::Active->value,
            'studentStatusExplicit' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function viewData(
        string $title,
        string $path,
        EnrollmentReportingPeriods $periods,
        ?EnrollmentReportingContext $context,
        bool $studentStatusFilterAvailable = false,
        bool $studentStatusExplicit = false,
    ): array {
        $selected = $context?->academicPeriod;
        $selectedGradeSections = $context?->gradeSectionFilter->keys() ?? [];
        $periodParameters = $selected === null ? [] : ['academic_period_id' => $selected->id];
        if ($studentStatusFilterAvailable && $studentStatusExplicit && $context !== null) {
            $periodParameters['student_status'] = $context->studentStatusFilter->value;
        }
        $reportParameters = $periodParameters;
        if ($selectedGradeSections !== []) {
            $reportParameters['grade_section'] = $selectedGradeSections;
        }
        $periodQuery = $reportParameters === []
            ? ''
            : '?' . http_build_query($reportParameters, '', '&', PHP_QUERY_RFC3986);
        $allGradeSectionsQuery = $periodParameters === []
            ? ''
            : '?' . http_build_query($periodParameters, '', '&', PHP_QUERY_RFC3986);

        return [
            'title' => $title,
            'reportPath' => $path,
            'periods' => $periods->periods,
            'selectedPeriod' => $selected,
            'selectedPeriodId' => $selected?->id,
            'selectionRequired' => $selected === null,
            'periodQuery' => $periodQuery,
            'allGradeSectionsQuery' => $allGradeSectionsQuery,
            'gradeSectionOptions' => $context?->gradeSectionOptions ?? [],
            'selectedGradeSections' => $selectedGradeSections,
            'gradeSectionFilterActive' => $selectedGradeSections !== [],
            'studentStatusFilterAvailable' => $studentStatusFilterAvailable,
            'selectedStudentStatus' => $context?->studentStatusFilter->value,
            'studentStatusExplicit' => $studentStatusExplicit,
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
            400 => $exception instanceof ReportingStudentStatusSelectionInvalid
                ? 'Selecciona un estado actual de estudiante válido.'
                : ($exception instanceof EnrollmentReportingSelectionInvalid
                    ? 'Selecciona combinaciones válidas de grado y paralelo.'
                    : 'Seleccione un período académico válido.'),
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

    private function downloadCsv(string $content, string $filename): string
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $this->noStore();
        http_response_code(200);

        return $content;
    }
}
