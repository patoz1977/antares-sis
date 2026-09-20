<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Reporting\AcademicPeriodReportingQuery;
use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryRow;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Dto\StudentBillingReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentMedicalReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Application\Reporting\EnrollmentSummaryQuery;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetEnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\GetStudentBillingReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\GetStudentMedicalReport;
use App\Enrollment\Application\Reporting\GetStudentRepresentativeDirectory;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Enrollment\Application\Reporting\StudentBillingReportQuery;
use App\Enrollment\Application\Reporting\StudentEnrollmentListQuery;
use App\Enrollment\Application\Reporting\StudentMedicalReportQuery;
use App\Enrollment\Application\Reporting\StudentRepresentativeDirectoryQuery;
use App\Enrollment\Http\EnrollmentReportCsvWriter;
use App\Enrollment\Http\EnrollmentReportingController;
use Tests\Support\TestRunner;

function registerEnrollmentReportingDeliveryTests(TestRunner $runner): void
{
    $runner->add('E013 Phase 3 route inventory freezes eleven protected GET routes without aliases or POST', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        $paths = [
            '/reports/enrollments',
            '/reports/enrollments/summary',
            '/reports/enrollments/students',
            '/reports/enrollments/directory',
            '/reports/enrollments/billing',
            '/reports/enrollments/medical',
            '/reports/enrollments/summary/csv',
            '/reports/enrollments/students/csv',
            '/reports/enrollments/directory/csv',
            '/reports/enrollments/billing/csv',
            '/reports/enrollments/medical/csv',
        ];
        foreach ($paths as $path) {
            assertSameValue(1, substr_count($routes, "\$router->get(\n    '" . $path . "',"), $path);
            assertSameValue(false, str_contains($routes, "\$router->post(\n    '" . $path . "',"), $path);
        }
        assertSameValue(11, substr_count($routes, "[\$enrollmentReportingController, '"));
        assertSameValue(false, str_contains($routes, '/admin/reports'));

        $normalizedCrLf = str_replace("\n", "\r\n", $routes);
        foreach ($paths as $path) {
            assertSameValue(1, substr_count(str_replace("\r\n", "\n", $normalizedCrLf), "\$router->get(\n    '" . $path . "',"));
        }
    });

    $runner->add('E013 Phase 3 all report endpoints reuse the exact anonymous non-admin admin gate', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        foreach ([
            '/reports/enrollments', '/reports/enrollments/summary', '/reports/enrollments/students',
            '/reports/enrollments/directory', '/reports/enrollments/billing', '/reports/enrollments/medical',
            '/reports/enrollments/summary/csv', '/reports/enrollments/students/csv',
            '/reports/enrollments/directory/csv', '/reports/enrollments/billing/csv',
            '/reports/enrollments/medical/csv',
        ] as $path) {
            $start = strpos($routes, "\$router->get(\n    '" . $path . "',");
            assertSameValue(true, is_int($start), $path);
            $block = substr($routes, (int) $start, 260);
            assertSameValue(true, str_contains($block, '$enrollmentAdministrationMiddleware'), $path);
        }

        $middleware = (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentAdministrationMiddleware.php');
        assertSameValue(true, str_contains($middleware, "header('Location: /login')"));
        assertSameValue(true, str_contains($middleware, "loginIdentifier !== 'admin'"));
        assertSameValue(true, str_contains($middleware, "status(403)"));
    });

    $runner->add('E013 Phase 3 hub shows ACTIVE INACTIVE default and period-preserving report links', function (): void {
        $fixture = e013ReportingFixture();
        e013ReportingRequest('/reports/enrollments');
        $html = $fixture['controller']->index();

        assertSameValue(200, http_response_code());
        e013Contains('P7 — Period 7', $html);
        e013Contains('P8 — Period 8 (Activo)', $html);
        e013Contains('value="8" selected', $html);
        foreach (['summary', 'students', 'directory', 'billing', 'medical'] as $report) {
            e013Contains('/reports/enrollments/' . $report . '?academic_period_id=8', $html);
        }

        $zero = e013ReportingFixture(activeId: null);
        e013ReportingRequest('/reports/enrollments');
        $html = $zero['controller']->index();
        assertSameValue(200, http_response_code());
        e013Contains('Selecciona un período académico', $html);
        assertSameValue(false, str_contains($html, ' selected'));
    });

    $runner->add('E013 Phase 3 period selection accepts ACTIVE INACTIVE and maps invalid absent and corrupt state safely', function (): void {
        $fixture = e013ReportingFixture();
        foreach ([7, 8] as $id) {
            e013ReportingRequest('/reports/enrollments/students', ['academic_period_id' => (string) $id]);
            $html = $fixture['controller']->students();
            assertSameValue(200, http_response_code());
            e013Contains('academic_period_id=' . $id, $html);
            assertSameValue($id, $fixture['students']->lastAcademicPeriodId);
        }

        foreach ([['invalid', 400], ['0', 400], [['array'], 400], ['99', 404]] as [$value, $status]) {
            e013ReportingRequest('/reports/enrollments/students', ['academic_period_id' => $value]);
            $html = $fixture['controller']->students();
            assertSameValue($status, http_response_code());
            assertSameValue(false, str_contains($html, 'SQLSTATE'));
            assertSameValue(false, str_contains($html, 'PDO'));
        }

        e013ReportingRequest('/reports/enrollments/students', ['status' => 'DRAFT']);
        assertSameValue(400, e013Status($fixture['controller'], 'students'));

        $zero = e013ReportingFixture(activeId: null);
        e013ReportingRequest('/reports/enrollments/students');
        $html = $zero['controller']->students();
        assertSameValue(200, http_response_code());
        e013Contains('Selecciona un período académico', $html);
        assertSameValue(0, $zero['students']->calls);

        $multiple = e013ReportingFixture(activeId: 8, multipleActive: true);
        e013ReportingRequest('/reports/enrollments');
        $html = $multiple['controller']->index();
        assertSameValue(500, http_response_code());
        e013Contains('No se pudo generar el reporte.', $html);
        assertSameValue(false, str_contains($html, 'ACTIVE AcademicPeriod'));
    });

    $runner->add('E013 Phase 3 five HTML reports render approved datasets empty states CSV links and escaped output', function (): void {
        $fixture = e013ReportingFixture();
        foreach ([
            ['summary', ['Resumen de matrículas', 'Total:', 'Borrador', 'Grade =SUM', 'Section A']],
            ['students', ['Lista de estudiantes', 'No iniciada', '&lt;script&gt;alert(1)&lt;/script&gt;']],
            ['directory', ['Directorio de estudiantes y representantes', '@something Representative', 'Address, with comma', 'Móvil:']],
            ['billing', ['Reporte de facturación', 'Tipo de identificación', 'Dirección de facturación', 'Nombre legal']],
            ['medical', ['Reporte médico', 'Condición médica', 'Sí', 'No', 'Observaciones']],
        ] as [$method, $expected]) {
            e013ReportingRequest('/reports/enrollments/' . $method, ['academic_period_id' => '8']);
            $html = $fixture['controller']->{$method}();
            assertSameValue(200, http_response_code());
            foreach ($expected as $text) {
                e013Contains($text, $html);
            }
            e013Contains('/reports/enrollments/' . $method . '/csv?academic_period_id=8', $html);
            assertSameValue(false, str_contains($html, '<script>alert(1)</script>'));
        }

        e013ReportingRequest('/reports/enrollments/directory', ['academic_period_id' => '7']);
        $historical = $fixture['controller']->directory();
        e013Contains(
            'El estado y la ubicación de la matrícula corresponden al período seleccionado. El contacto y la dirección son datos actuales del SIS.',
            $historical,
        );
        assertSameValue(false, stripos($historical, 'submitter') !== false);

        $empty = e013ReportingFixture(empty: true);
        foreach (['summary', 'students', 'directory', 'billing', 'medical'] as $method) {
            e013ReportingRequest('/reports/enrollments/' . $method, ['academic_period_id' => '8']);
            $html = $empty['controller']->{$method}();
            assertSameValue(true, str_contains($html, 'No hay matrículas') || str_contains($html, 'No hay estudiantes activos'));
        }
    });

    $runner->add('E013 Phase 3 CSV writer emits exact headers CRLF deterministic rows and formula neutralization', function (): void {
        $fixture = e013ReportingFixture();
        $writer = new EnrollmentReportCsvWriter();
        $exports = [
            [$writer->summary($fixture['summaryService']->handle(8)), ['AcademicPeriod', 'Grade', 'Section', 'EnrollmentStatus', 'Count'], 2],
            [$writer->students($fixture['students']->rows), ['Grade', 'Section', 'Student', 'Status'], 2],
            [$writer->directory($fixture['directory']->rows), [
                'Grade', 'Section', 'Student', 'StudentIdentificationType', 'StudentIdentificationNumber',
                'PrimaryRepresentative', 'RepresentativeIdentificationType', 'RepresentativeIdentificationNumber',
                'MobilePhone', 'LandlinePhone', 'PersonalEmail', 'WorkPhone', 'WorkEmail', 'Address', 'Status',
            ], 2],
            [$writer->billing($fixture['billing']->rows), [
                'Grade', 'Section', 'Student', 'Status', 'IdentificationType', 'IdentificationNumber',
                'LegalName', 'BillingAddress', 'BillingEmail', 'Phone',
            ], 2],
            [$writer->medical($fixture['medical']->rows), [
                'Grade', 'Section', 'Student', 'Status', 'HasMedicalCondition', 'MedicalConditionDetail',
                'HasAllergies', 'AllergyDetail', 'TakesPermanentMedication', 'MedicationName',
                'RequiresSpecialCare', 'SpecialCareDetail', 'HasMedicalInsurance', 'InsuranceProvider',
                'PediatricianName', 'PediatricianPhone', 'Observations',
            ], 2],
        ];
        foreach ($exports as [$csv, $header, $lineCount]) {
            assertSameValue(true, mb_check_encoding($csv, 'UTF-8'));
            assertSameValue(true, str_contains($csv, "\r\n"));
            $records = e013CsvRecords($csv);
            assertSameValue($header, $records[0]);
            assertSameValue($lineCount, count($records));
            assertSameValue(count($header), count($records[1]));
        }

        $directory = $exports[2][0];
        foreach (["'=SUM", "'+593", "'-1", "'@something", "'\tTabbed", "'\rCarriage"] as $protected) {
            e013Contains($protected, $directory);
        }
        e013Contains('Normal text', $directory);
        assertSameValue(false, str_contains($directory, "'Normal text"));
        e013Contains("Address, with comma\nand newline", $directory);

        assertSameValue(1, count(e013CsvRecords($writer->students([]))));
        assertSameValue(1, count(e013CsvRecords($writer->directory([]))));
        assertSameValue(1, count(e013CsvRecords($writer->billing([]))));
        assertSameValue(1, count(e013CsvRecords($writer->medical([]))));
    });

    $runner->add('E013 Phase 3 CSV actions use the same services and safe response contract without partial output', function (): void {
        $fixture = e013ReportingFixture();
        $expectedPrefixes = [
            'summaryCsv' => 'enrollment-summary-period-',
            'studentsCsv' => 'student-enrollment-period-',
            'directoryCsv' => 'student-directory-period-',
            'billingCsv' => 'student-billing-period-',
            'medicalCsv' => 'student-medical-period-',
        ];
        foreach ($expectedPrefixes as $method => $prefix) {
            e013ReportingRequest('/reports/enrollments/' . strtolower($method), ['academic_period_id' => '8']);
            $csv = $fixture['controller']->{$method}();
            assertSameValue(200, http_response_code());
            assertSameValue(true, str_ends_with($csv, "\r\n"));
            e013Contains($prefix, (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php'));
        }

        $source = (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php');
        foreach ([
            "Content-Type: text/csv; charset=UTF-8",
            'Content-Disposition: attachment; filename=',
            'Cache-Control: no-store',
            '$dataset = $load($selected->id);',
            '$content = $write($dataset);',
        ] as $expected) {
            e013Contains($expected, $source);
        }
        assertSameValue(true, strpos($source, '$content = $write($dataset);') < strpos($source, "header('Content-Type: text/csv"));

        e013ReportingRequest('/reports/enrollments/students/csv', ['academic_period_id' => 'invalid']);
        $error = $fixture['controller']->studentsCsv();
        assertSameValue(400, http_response_code());
        assertSameValue(false, str_contains($error, 'Grade,Section'));
    });

    $runner->add('E013 Phase 3 Delivery remains thin read-only private and schema neutral', function (): void {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php');
        foreach (['new PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'submitted_by', 'SubmissionSnapshot'] as $forbidden) {
            assertSameValue(false, stripos($controller, $forbidden) !== false, $forbidden);
        }
        assertSameValue(false, file_exists(dirname(__DIR__) . '/database/migrations/011_create_reporting.php'));
        assertSameValue(false, str_contains($controller, 'session'));
        assertSameValue(false, str_contains($controller, 'logger'));

        $dashboard = (string) file_get_contents(dirname(__DIR__) . '/resources/views/dashboard/index.php');
        e013Contains('Reportes', $dashboard);
        e013Contains('canAccessPersons', $dashboard);

        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        e013Contains('EnrollmentReportingController::class, EnrollmentReportingController::class', $bootstrap);
        e013Contains('EnrollmentReportCsvWriter::class, EnrollmentReportCsvWriter::class', $bootstrap);
    });
}

/** @return array<string, mixed> */
function e013ReportingFixture(?int $activeId = 8, bool $multipleActive = false, bool $empty = false): array
{
    $periods = [
        reportingPeriod(7, $multipleActive ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
        reportingPeriod(8, $activeId === 8 ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
    ];
    $periodQuery = new class($periods) implements AcademicPeriodReportingQuery {
        public function __construct(public array $periods)
        {
        }
        public function findAll(): array
        {
            return $this->periods;
        }
    };
    $resolver = new ResolveEnrollmentReportingPeriod(new InMemoryAcademicPeriodRepository([
        academicPeriodFixture(7, $multipleActive ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
        academicPeriodFixture(8, $activeId === 8 ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
    ]));

    $summaryRows = $empty ? [] : [new EnrollmentSummaryRow(
        EnrollmentReportingStatus::Draft,
        1,
        'Grade =SUM',
        2,
        'Section A',
        1,
    )];
    $summary = new class($summaryRows) implements EnrollmentSummaryQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public function fetch(int $academicPeriodId): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;

            return $this->rows;
        }
    };
    $studentRows = $empty ? [] : [new StudentEnrollmentReportRow(
        10,
        1,
        'Grade =SUM',
        2,
        'Section A',
        '<script>alert(1)</script>',
        'Student',
        EnrollmentReportingStatus::NotStarted,
    )];
    $students = new class($studentRows) implements StudentEnrollmentListQuery {
        public int $calls = 0;
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public function fetch(int $academicPeriodId): array
        {
            ++$this->calls;
            $this->lastAcademicPeriodId = $academicPeriodId;

            return $this->rows;
        }
    };
    $directoryRows = $empty ? [] : [new StudentRepresentativeDirectoryRow(
        10,
        1,
        '=SUM Grade',
        2,
        'Section A',
        '<script>alert(1)</script>',
        'Student',
        '=SUM(TYPE)',
        '-123',
        '@something',
        'Representative',
        'ID',
        "\tTabbed",
        '+593000000',
        "\rCarriage",
        'normal@example.test',
        'Normal text',
        'work@example.test',
        "Address, with comma\nand newline",
        EnrollmentReportingStatus::Draft,
    )];
    $directory = new class($directoryRows) implements StudentRepresentativeDirectoryQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public function fetch(int $academicPeriodId): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;

            return $this->rows;
        }
    };
    $billingRows = $empty ? [] : [new StudentBillingReportRow(
        10, 1, 'Grade 1', 2, 'Section A', 'Surname', 'Name', EnrollmentReportingStatus::Submitted,
        'RUC', '123', 'Legal Name', 'Billing Address', 'billing@example.test', '+593111',
    )];
    $billing = new class($billingRows) implements StudentBillingReportQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public function fetch(int $academicPeriodId): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;

            return $this->rows;
        }
    };
    $medicalRows = $empty ? [] : [new StudentMedicalReportRow(
        10, 1, 'Grade 1', 2, 'Section A', 'Surname', 'Name', EnrollmentReportingStatus::Completed,
        true, 'Condition', false, null, null, null, true, 'Care', false, null,
        'Pediatrician', '+593222', '<script>alert(1)</script>',
    )];
    $medical = new class($medicalRows) implements StudentMedicalReportQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public function fetch(int $academicPeriodId): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;

            return $this->rows;
        }
    };

    $summaryService = new GetEnrollmentSummaryReport($resolver, $summary);
    $controller = new EnrollmentReportingController(
        new GetEnrollmentReportingPeriods($periodQuery),
        $resolver,
        $summaryService,
        new GetStudentEnrollmentReport($resolver, $students),
        new GetStudentRepresentativeDirectory($resolver, $directory),
        new GetStudentBillingReport($resolver, $billing),
        new GetStudentMedicalReport($resolver, $medical),
        new EnrollmentReportCsvWriter(),
    );

    return compact('controller', 'summaryService', 'summary', 'students', 'directory', 'billing', 'medical');
}

/** @param array<string, mixed> $query */
function e013ReportingRequest(string $uri, array $query = []): void
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $query;
    $_POST = [];
    http_response_code(200);
}

function e013Status(EnrollmentReportingController $controller, string $method): int
{
    $controller->{$method}();

    return http_response_code();
}

/** @return list<list<string>> */
function e013CsvRecords(string $csv): array
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new \RuntimeException('Test stream unavailable.');
    }
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

function e013Contains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException('Expected report output to contain "' . $needle . '".');
    }
}
