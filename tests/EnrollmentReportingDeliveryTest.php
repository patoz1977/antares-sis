<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Reporting\AcademicPeriodReportingQuery;
use App\Enrollment\Application\Reporting\Dto\DirectoryRepresentative;
use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryRow;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureAuthorizedPickup;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;
use App\Enrollment\Application\Reporting\Dto\ReportingGradeSectionOption;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Dto\StudentBillingReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentMedicalReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Application\Reporting\EnrollmentSummaryQuery;
use App\Enrollment\Application\Reporting\GradeSectionReportingQuery;
use App\Enrollment\Application\Reporting\GetEnrollmentReportingPeriods;
use App\Enrollment\Application\Reporting\GetEnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\GetPhysicalDepartureReport;
use App\Enrollment\Application\Reporting\GetStudentBillingReport;
use App\Enrollment\Application\Reporting\GetStudentEnrollmentReport;
use App\Enrollment\Application\Reporting\GetStudentMedicalReport;
use App\Enrollment\Application\Reporting\GetStudentRepresentativeDirectory;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingPeriod;
use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;
use App\Enrollment\Application\Reporting\PhysicalDepartureReportQuery;
use App\Enrollment\Application\Reporting\ResolveEnrollmentReportingContext;
use App\Enrollment\Application\Reporting\ResolveInspectionReportingContext;
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
        assertSameValue(11, count($paths));
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

    $runner->add('Phase 5 adds exactly two protected inspection GET routes and no POST route', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        foreach (['/reports/enrollments/inspection', '/reports/enrollments/inspection/csv'] as $path) {
            assertSameValue(1, substr_count($routes, "\$router->get(\n    '" . $path . "',"), $path);
            assertSameValue(false, str_contains($routes, "\$router->post(\n    '" . $path . "',"), $path);
            $start = strpos($routes, "\$router->get(\n    '" . $path . "',");
            assertSameValue(true, is_int($start));
            assertSameValue(true, str_contains(substr($routes, (int) $start, 260), '$enrollmentAdministrationMiddleware'));
        }
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

    $runner->add('Reporting Delivery exposes actual Grade Section options with explicit All and preserves multi-selection', function (): void {
        $fixture = e013ReportingFixture();
        e013ReportingRequest('/reports/enrollments/students', [
            'academic_period_id' => '8',
            'grade_section' => ['EGB_2:B', 'EGB_1:A', 'EGB_2:B'],
        ]);
        $html = $fixture['controller']->students();

        assertSameValue(200, http_response_code());
        e013Contains('Grados y paralelos', $html);
        e013Contains('Grade 1 — Section A', $html);
        e013Contains('Grade 2 — Section B', $html);
        e013Contains('value="EGB_1:A" checked', $html);
        e013Contains('value="EGB_2:B" checked', $html);
        e013Contains('Mostrar todos', $html);
        e013Contains(
            'academic_period_id=8&amp;grade_section%5B0%5D=EGB_2%3AB&amp;grade_section%5B1%5D=EGB_1%3AA',
            $html,
        );
        assertSameValue(['EGB_2:B', 'EGB_1:A'], $fixture['students']->lastFilter?->keys());
        assertSameValue(false, str_contains($html, 'Sin sección'));

        e013ReportingRequest('/reports/enrollments/students', ['academic_period_id' => '8']);
        $all = $fixture['controller']->students();
        e013Contains('<span class="badge text-bg-primary">Todos</span>', $all);
        assertSameValue(true, $fixture['students']->lastFilter?->isAll());
    });

    $runner->add('Reporting Delivery rejects malformed unknown and wrong-period Grade Section selections safely', function (): void {
        $fixture = e013ReportingFixture();
        foreach ([
            'EGB_1:A',
            [''],
            ['EGB_1'],
            ['UNKNOWN:A'],
            ['EGB_1:B'],
        ] as $selection) {
            e013ReportingRequest('/reports/enrollments/students', [
                'academic_period_id' => '8',
                'grade_section' => $selection,
            ]);
            $html = $fixture['controller']->students();
            assertSameValue(400, http_response_code());
            e013Contains('Selecciona combinaciones válidas de grado y paralelo.', $html);
            assertSameValue(false, str_contains($html, 'SQLSTATE'));
        }

        e013ReportingRequest('/reports/enrollments/students', [
            'academic_period_id' => '7',
            'grade_section' => ['EGB_1:A'],
        ]);
        assertSameValue(400, e013Status($fixture['controller'], 'students'));
    });

    $runner->add('Reporting Delivery applies the same normalized filter to HTML and CSV for all five reports', function (): void {
        $fixture = e013ReportingFixture();
        $query = [
            'academic_period_id' => '8',
            'grade_section' => ['EGB_1:A', 'EGB_2:B', 'EGB_1:A'],
        ];
        foreach ([
            ['summary', 'summary'],
            ['students', 'students'],
            ['directory', 'directory'],
            ['billing', 'billing'],
            ['medical', 'medical'],
        ] as [$method, $fixtureKey]) {
            e013ReportingRequest('/reports/enrollments/' . $method, $query);
            $fixture['controller']->{$method}();
            assertSameValue(['EGB_1:A', 'EGB_2:B'], $fixture[$fixtureKey]->lastFilter?->keys());

            $csvMethod = $method . 'Csv';
            e013ReportingRequest('/reports/enrollments/' . $method . '/csv', $query);
            $csv = $fixture['controller']->{$csvMethod}();
            assertSameValue(200, http_response_code());
            assertSameValue('efbbbf', bin2hex(substr($csv, 0, 3)));
            assertSameValue(['EGB_1:A', 'EGB_2:B'], $fixture[$fixtureKey]->lastFilter?->keys());
        }
    });

    $runner->add('Phase 5 inspection HTML uses ACTIVE context one Student row complete pickups escaping and no historical selector', function (): void {
        $fixture = e013ReportingFixture();
        e013ReportingRequest('/reports/enrollments/inspection', [
            'grade_section' => ['EGB_1:A', 'EGB_1:A'],
        ]);
        $html = $fixture['controller']->inspection();

        assertSameValue(200, http_response_code());
        foreach ([
            'Salida y retiro de estudiantes', 'Información operativa actual para Inspección',
            'Período académico activo:', 'P8 — Period 8', 'No puede salir solo',
            '&lt;Pickup One&gt;', 'Pickup Two', '0991111111',
        ] as $expected) {
            e013Contains($expected, $html);
        }
        assertSameValue(1, substr_count($html, '&lt;Student&gt;'));
        assertSameValue(false, str_contains($html, '<select'));
        assertSameValue(false, str_contains($html, 'name="academic_period_id"'));
        e013Contains('/reports/enrollments/inspection/csv?grade_section%5B0%5D=EGB_1%3AA', $html);
        assertSameValue(['EGB_1:A'], $fixture['physicalDeparture']->lastFilter?->keys());

        e013ReportingRequest('/reports/enrollments/inspection', ['academic_period_id' => '7']);
        assertSameValue(400, e013Status($fixture['controller'], 'inspection'));
        $none = e013ReportingFixture(activeId: null);
        e013ReportingRequest('/reports/enrollments/inspection');
        assertSameValue(404, e013Status($none['controller'], 'inspection'));
    });

    $runner->add('Phase 5 inspection CSV shares filter flattens pickups and preserves safe atomic output', function (): void {
        $fixture = e013ReportingFixture();
        e013ReportingRequest('/reports/enrollments/inspection/csv', [
            'grade_section' => ['EGB_1:A', 'EGB_1:A'],
        ]);
        $csv = $fixture['controller']->inspectionCsv();
        $records = e013CsvRecords($csv);

        assertSameValue(200, http_response_code());
        assertSameValue('efbbbf', bin2hex(substr($csv, 0, 3)));
        assertSameValue([
            'Grade', 'Section', 'StudentFirstName', 'StudentMiddleName', 'StudentFirstSurname',
            'StudentSecondSurname', 'StudentIdentificationType', 'StudentIdentificationNumber',
            'DepartureState', 'PickupName', 'PickupRelationship', 'PickupIdentificationType',
            'PickupIdentificationNumber', 'PickupMobilePhone',
        ], $records[0]);
        assertSameValue(3, count($records));
        assertSameValue('<Student>', $records[1][2]);
        assertSameValue('<Pickup One>', $records[1][9]);
        assertSameValue("'=SUM(ID)", $records[1][7]);
        assertSameValue("'+123", $records[1][12]);
        assertSameValue(['EGB_1:A'], $fixture['physicalDeparture']->lastFilter?->keys());

        $withoutPickup = new PhysicalDepartureReportRow(
            12, null, null, 'No', null, 'Pickup', null, null, null, false, [],
        );
        $blank = e013CsvRecords((new EnrollmentReportCsvWriter())->physicalDeparture([$withoutPickup]));
        assertSameValue(2, count($blank));
        assertSameValue('', $blank[1][9]);
        assertSameValue('Sin persona autorizada registrada', $blank[1][8]);
    });

    $runner->add('E013 Phase 3 five HTML reports render approved datasets empty states CSV links and escaped output', function (): void {
        $fixture = e013ReportingFixture();
        foreach ([
            ['summary', ['Resumen de matrículas', 'Total:', 'Borrador', '=SUM, Grado Ñandú', 'Sección A']],
            ['students', ['Lista de estudiantes', 'No iniciada', '&lt;script&gt;alert(1)&lt;/script&gt;']],
            ['directory', [
                'Directorio de estudiantes y representantes', 'Representante 1', 'Representante 2',
                '@something Representative', 'Relación con la familia:', 'Dirección, con coma', 'Móvil:',
            ]],
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
            [$writer->summary($fixture['summaryService']->handle(8)), ['AcademicPeriod', 'Grade', 'Section', 'EnrollmentStatus', 'Count'], 2, 'Ñandú', "\"'=SUM, Grado Ñandú\""],
            [$writer->students($fixture['students']->rows), ['Grade', 'Section', 'Student', 'Status'], 2, 'Ñandú', "\"'=SUM, Grado Ñandú\""],
            [$writer->directory($fixture['directory']->rows), [
                'Grade', 'Section', 'EnrollmentStatus', 'StudentFirstName', 'StudentMiddleName',
                'StudentFirstSurname', 'StudentSecondSurname', 'StudentIdentificationType',
                'StudentIdentificationNumber', 'Representative1FirstName', 'Representative1MiddleName',
                'Representative1FirstSurname', 'Representative1SecondSurname', 'Representative1Relationship',
                'Representative1IdentificationType', 'Representative1IdentificationNumber',
                'Representative1MobilePhone', 'Representative1LandlinePhone', 'Representative1PersonalEmail',
                'Representative2FirstName', 'Representative2MiddleName', 'Representative2FirstSurname',
                'Representative2SecondSurname', 'Representative2Relationship',
                'Representative2IdentificationType', 'Representative2IdentificationNumber',
                'Representative2MobilePhone', 'Representative2LandlinePhone',
                'Representative2PersonalEmail', 'Address',
            ], 2, 'Dirección', "\"Dirección, con coma\nand nueva línea\""],
            [$writer->billing($fixture['billing']->rows), [
                'Grade', 'Section', 'Student', 'Status', 'IdentificationType', 'IdentificationNumber',
                'LegalName', 'BillingAddress', 'BillingEmail', 'Phone',
            ], 2, 'Razón', "\"'=SUM, Razón Social\""],
            [$writer->medical($fixture['medical']->rows), [
                'Grade', 'Section', 'Student', 'Status', 'HasMedicalCondition', 'MedicalConditionDetail',
                'HasAllergies', 'AllergyDetail', 'TakesPermanentMedication', 'MedicationName',
                'RequiresSpecialCare', 'SpecialCareDetail', 'HasMedicalInsurance', 'InsuranceProvider',
                'PediatricianName', 'PediatricianPhone', 'Observations',
            ], 2, 'Condición', "\"'=SUM, Condición Ñandú\""],
        ];
        foreach ($exports as [$csv, $header, $lineCount, $spanishText, $quotedText]) {
            assertSameValue('efbbbf', bin2hex(substr($csv, 0, 3)));
            $payload = e013CsvPayload($csv);
            assertSameValue(false, str_contains($payload, "\xEF\xBB\xBF"));
            assertSameValue(true, mb_check_encoding($csv, 'UTF-8'));
            assertSameValue(true, str_contains($payload, "\r\n"));
            e013Contains($spanishText, $payload);
            e013Contains($quotedText, $payload);
            e013Contains("'=SUM", $payload);
            $records = e013CsvRecords($csv);
            assertSameValue($header, $records[0]);
            assertSameValue($lineCount, count($records));
            assertSameValue(count($header), count($records[1]));
        }

        $directory = $exports[2][0];
        foreach (["'=SUM", "'+593", "'-1", "'@something", "'\tTabbed", "'\rCarriage"] as $protected) {
            e013Contains($protected, $directory);
        }
        e013Contains('normal@example.test', $directory);
        assertSameValue(false, str_contains($directory, "'normal@example.test"));
        e013Contains("Dirección, con coma\nand nueva línea", $directory);
        assertSameValue(false, str_contains($directory, 'WorkPhone'));
        assertSameValue(false, str_contains($directory, 'WorkEmail'));

        $blankRepresentativeCsv = $writer->directory([new StudentRepresentativeDirectoryRow(
            11,
            null,
            null,
            null,
            null,
            'Student',
            null,
            'Without Representatives',
            null,
            null,
            null,
            null,
            null,
            null,
            EnrollmentReportingStatus::NotStarted,
        )]);
        $blankRecords = e013CsvRecords($blankRepresentativeCsv);
        assertSameValue(2, count($blankRecords));
        assertSameValue(count($blankRecords[0]), count($blankRecords[1]));
        assertSameValue('', $blankRecords[1][9]);
        assertSameValue('', $blankRecords[1][19]);

        assertSameValue(1, count(e013CsvRecords($writer->students([]))));
        assertSameValue(1, count(e013CsvRecords($writer->directory([]))));
        assertSameValue(1, count(e013CsvRecords($writer->billing([]))));
        assertSameValue(1, count(e013CsvRecords($writer->medical([]))));
    });

    $runner->add('E013 Phase 3 CSV actions use the same services and safe response contract without partial output', function (): void {
        $fixture = e013ReportingFixture();
        $expectedContracts = [
            'summaryCsv' => ['enrollment-summary-period-', 'Ñandú', "\"'=SUM, Grado Ñandú\""],
            'studentsCsv' => ['student-enrollment-period-', 'Ñandú', "\"'=SUM, Grado Ñandú\""],
            'directoryCsv' => ['student-directory-period-', 'Dirección', "\"Dirección, con coma\nand nueva línea\""],
            'billingCsv' => ['student-billing-period-', 'Razón', "\"'=SUM, Razón Social\""],
            'medicalCsv' => ['student-medical-period-', 'Condición', "\"'=SUM, Condición Ñandú\""],
        ];
        foreach ($expectedContracts as $method => [$prefix, $spanishText, $quotedText]) {
            e013ReportingRequest('/reports/enrollments/' . strtolower($method), ['academic_period_id' => '8']);
            $csv = $fixture['controller']->{$method}();
            assertSameValue(200, http_response_code());
            assertSameValue('efbbbf', bin2hex(substr($csv, 0, 3)));
            assertSameValue(1, substr_count($csv, "\xEF\xBB\xBF"));
            $payload = e013CsvPayload($csv);
            assertSameValue(false, str_contains($payload, "\xEF\xBB\xBF"));
            assertSameValue(true, mb_check_encoding($payload, 'UTF-8'));
            assertSameValue(true, str_contains($payload, "\r\n"));
            e013Contains($spanishText, $payload);
            e013Contains($quotedText, $payload);
            e013Contains("'=SUM", $payload);
            assertSameValue(true, count(e013CsvRecords($csv)) >= 2);
            assertSameValue(true, str_ends_with($csv, "\r\n"));
            e013Contains($prefix, (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php'));
        }

        $source = (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php');
        foreach ([
            "Content-Type: text/csv; charset=UTF-8",
            'Content-Disposition: attachment; filename=',
            'Cache-Control: no-store',
            '$load($context->academicPeriod->id, $context->gradeSectionFilter);',
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
    $periodRepository = new InMemoryAcademicPeriodRepository([
        academicPeriodFixture(7, $multipleActive ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
        academicPeriodFixture(8, $activeId === 8 ? AcademicPeriodStatus::Active : AcademicPeriodStatus::Inactive),
    ]);
    $resolver = new ResolveEnrollmentReportingPeriod($periodRepository);

    $summaryRows = $empty ? [] : [new EnrollmentSummaryRow(
        EnrollmentReportingStatus::Draft,
        1,
        '=SUM, Grado Ñandú',
        2,
        'Sección A',
        1,
    )];
    $summary = new class($summaryRows) implements EnrollmentSummaryQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function fetch(
            int $academicPeriodId,
            ?ReportingGradeSectionFilter $gradeSectionFilter = null,
        ): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;
            $this->lastFilter = $gradeSectionFilter;

            return $this->rows;
        }
    };
    $studentRows = $empty ? [] : [new StudentEnrollmentReportRow(
        10,
        1,
        '=SUM, Grado Ñandú',
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
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function fetch(
            int $academicPeriodId,
            ?ReportingGradeSectionFilter $gradeSectionFilter = null,
        ): array
        {
            ++$this->calls;
            $this->lastAcademicPeriodId = $academicPeriodId;
            $this->lastFilter = $gradeSectionFilter;

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
        null,
        'Student',
        null,
        '=SUM(TYPE)',
        '-123',
        new DirectoryRepresentative(
            '@something',
            null,
            'Representative',
            null,
            'Madre',
            'ID',
            "\tTabbed",
            '+593000000',
            "\rCarriage",
            'normal@example.test',
        ),
        new DirectoryRepresentative(
            'Second',
            null,
            'Representative',
            null,
            'Padre',
            null,
            null,
            null,
            null,
            null,
        ),
        "Dirección, con coma\nand nueva línea",
        EnrollmentReportingStatus::Draft,
    )];
    $directory = new class($directoryRows) implements StudentRepresentativeDirectoryQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function fetch(
            int $academicPeriodId,
            ?ReportingGradeSectionFilter $gradeSectionFilter = null,
        ): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;
            $this->lastFilter = $gradeSectionFilter;

            return $this->rows;
        }
    };
    $billingRows = $empty ? [] : [new StudentBillingReportRow(
        10, 1, 'Grade 1', 2, 'Section A', 'Surname', 'Name', EnrollmentReportingStatus::Submitted,
        'RUC', '123', '=SUM, Razón Social', 'Billing Address', 'billing@example.test', '+593111',
    )];
    $billing = new class($billingRows) implements StudentBillingReportQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function fetch(
            int $academicPeriodId,
            ?ReportingGradeSectionFilter $gradeSectionFilter = null,
        ): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;
            $this->lastFilter = $gradeSectionFilter;

            return $this->rows;
        }
    };
    $medicalRows = $empty ? [] : [new StudentMedicalReportRow(
        10, 1, 'Grade 1', 2, 'Section A', 'Surname', 'Name', EnrollmentReportingStatus::Completed,
        true, '=SUM, Condición Ñandú', false, null, null, null, true, 'Care', false, null,
        'Pediatrician', '+593222', '<script>alert(1)</script>',
    )];
    $medical = new class($medicalRows) implements StudentMedicalReportQuery {
        public ?int $lastAcademicPeriodId = null;
        public function __construct(public array $rows)
        {
        }
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function fetch(
            int $academicPeriodId,
            ?ReportingGradeSectionFilter $gradeSectionFilter = null,
        ): array
        {
            $this->lastAcademicPeriodId = $academicPeriodId;
            $this->lastFilter = $gradeSectionFilter;

            return $this->rows;
        }
    };
    $physicalDepartureRows = $empty ? [] : [new PhysicalDepartureReportRow(
        10,
        '=SUM, Grado Ñandú',
        'Section A',
        '<Student>',
        null,
        'Surname',
        null,
        'ID',
        '=SUM(ID)',
        false,
        [
            new PhysicalDepartureAuthorizedPickup('<Pickup One>', 'Madre', 'ID', '+123', '0991111111'),
            new PhysicalDepartureAuthorizedPickup('Pickup Two', 'Padre', null, null, '0992222222'),
        ],
    )];
    $physicalDeparture = new class($physicalDepartureRows) implements PhysicalDepartureReportQuery {
        public ?ReportingGradeSectionFilter $lastFilter = null;
        public function __construct(public array $rows) {}
        public function fetch(int $academicPeriodId, ?ReportingGradeSectionFilter $gradeSectionFilter = null): array
        {
            $this->lastFilter = $gradeSectionFilter;
            return $this->rows;
        }
    };

    $summaryService = new GetEnrollmentSummaryReport($resolver, $summary);
    $gradeSections = new class implements GradeSectionReportingQuery {
        public function findForAcademicPeriod(int $academicPeriodId): array
        {
            if ($academicPeriodId === 7) {
                return [reportingGradeSection(2, 'EGB_2', 'Grade 2', 2, 20, 'B', 'Section B')];
            }

            return [
                reportingGradeSection(1, 'EGB_1', 'Grade 1', 1, 10, 'A', 'Section A'),
                reportingGradeSection(2, 'EGB_2', 'Grade 2', 2, 20, 'B', 'Section B'),
            ];
        }
    };
    $reportingContext = new ResolveEnrollmentReportingContext($resolver, $gradeSections);
    $controller = new EnrollmentReportingController(
        new GetEnrollmentReportingPeriods($periodQuery),
        $reportingContext,
        $summaryService,
        new GetStudentEnrollmentReport($resolver, $students),
        new GetStudentRepresentativeDirectory($resolver, $directory),
        new GetStudentBillingReport($resolver, $billing),
        new GetStudentMedicalReport($resolver, $medical),
        new ResolveInspectionReportingContext(new GetActiveAcademicPeriod($periodRepository), $reportingContext),
        new GetPhysicalDepartureReport($physicalDeparture),
        new EnrollmentReportCsvWriter(),
    );

    return compact(
        'controller', 'summaryService', 'summary', 'students', 'directory', 'billing', 'medical',
        'physicalDeparture',
    );
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
    fwrite($stream, e013CsvPayload($csv));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

function e013CsvPayload(string $csv): string
{
    $bom = "\xEF\xBB\xBF";
    if (substr($csv, 0, 3) !== $bom || substr_count($csv, $bom) !== 1) {
        throw new \RuntimeException('Expected exactly one UTF-8 BOM at the start of the CSV.');
    }

    return substr($csv, 3);
}

function e013Contains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException('Expected report output to contain "' . $needle . '".');
    }
}
