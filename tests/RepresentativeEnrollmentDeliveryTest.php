<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Application\RepresentativePortal\Dto\ResolveOrStartRepresentativeEnrollmentInput;
use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\FamilyId as EnrollmentFamilyId;
use App\Enrollment\Domain\ValueObject\StudentId as EnrollmentStudentId;
use App\Enrollment\Http\RepresentativeEnrollmentController;
use App\Enrollment\Http\RepresentativeEnrollmentInputMapper;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentId;
use App\Person\Http\PersonFormOption;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativeEnrollmentDeliveryTests(TestRunner $runner): void
{
    $runner->add('E011 Delivery exposes exact authenticated Enrollment routes without lifecycle authority', function (): void {
        $routes = representativeEnrollmentNormalizedSource('routes/web.php');
        $paths = [
            '/representative/enrollment',
            '/representative/enrollment/open',
            '/representative/enrollment/representative/personal',
            '/representative/enrollment/representative/contact',
            '/representative/enrollment/representative/employment',
            '/representative/enrollment/student/personal',
            '/representative/enrollment/student/billing',
            '/representative/enrollment/student/medical',
            '/representative/enrollment/student/transport',
            '/representative/enrollment/student/leave-alone',
        ];
        foreach ($paths as $path) {
            assertSameValue(1, substr_count($routes, "'" . $path . "'"), $path);
        }
        assertSameValue(10, substr_count($routes, '[$representativeEnrollmentController,'));
        $start = strpos($routes, "\$router->get(\n    '/representative/enrollment'");
        $end = strpos($routes, "\$router->get(\n    '/representative/resources'", is_int($start) ? $start : 0);
        $slice = is_int($start) && is_int($end) ? substr($routes, $start, $end - $start) : '';
        assertSameValue(10, substr_count($slice, 'AuthenticationMiddleware::class'));
        foreach (['AdministrationMiddleware', 'family_id}', 'academic_period_id}', 'enrollment_id', '/submit', '/complete', '/cancel', '/reopen'] as $forbidden) {
            assertSameValue(false, str_contains($slice, $forbidden), $forbidden);
        }
    });

    $runner->add('E011 Enrollment GET handles authority period acknowledgement and Student states without writes', function (): void {
        $noFamily = representativeEnrollmentDeliveryFixture(familyCount: 0);
        deliveryRequest('GET', '/representative/enrollment');
        assertSameValue(403, representativeEnrollmentStatus($noFamily['controller']));

        $nonRepresentative = representativeEnrollmentDeliveryFixture(representativeExists: false, familyCount: 0);
        deliveryRequest('GET', '/representative/enrollment');
        assertSameValue(403, representativeEnrollmentStatus($nonRepresentative['controller']));

        $selection = representativeEnrollmentDeliveryFixture(familyCount: 2);
        deliveryRequest('GET', '/representative/enrollment');
        assertSameValue('', $selection['controller']->index());
        assertSameValue(302, http_response_code());

        $noPeriod = representativeEnrollmentDeliveryFixture(periodActive: false);
        deliveryRequest('GET', '/representative/enrollment');
        $noPeriodHtml = $noPeriod['controller']->index();
        deliveryAssertContains('No active Academic Period', $noPeriodHtml);
        assertSameValue(false, str_contains($noPeriodHtml, 'Start Enrollment Draft'));

        $pending = representativeEnrollmentDeliveryFixture(acknowledgementsSatisfied: false);
        deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
        $pendingHtml = $pending['controller']->index();
        deliveryAssertContains('Institutional Acknowledgements are required', $pendingHtml);
        deliveryAssertContains('/representative/acknowledgements', $pendingHtml);
        assertSameValue(false, str_contains($pendingHtml, 'Start Enrollment Draft'));
        assertSameValue(false, str_contains($pendingHtml, 'href="/representative/resources"'));

        $ready = representativeEnrollmentDeliveryFixture();
        deliveryRequest('GET', '/representative/enrollment');
        $unselected = $ready['controller']->index();
        deliveryAssertContains('Representative Personal Information', $unselected);
        deliveryAssertContains('Choose a Student', $unselected);
        assertSameValue(0, $ready['services']['enrollments']->saveCalls);

        deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
        $selected = $ready['controller']->index();
        deliveryAssertContains('Enrollment Draft has not been started.', $selected);
        deliveryAssertContains('Institutional code', $selected);
        assertSameValue(0, $ready['services']['enrollments']->saveCalls);
    });

    $runner->add('E011 Draft initialization is explicit CSRF-protected idempotent and uses PRG', function (): void {
        $fixture = representativeEnrollmentDeliveryFixture();
        $invalid = representativeEnrollmentPost($fixture['controller'], 'open', [
            '_csrf_token' => 'invalid',
            'expected_family_id' => '77',
            'expected_academic_period_id' => '5',
            'student_id' => '44',
        ]);
        assertSameValue(403, http_response_code());
        deliveryAssertContains('could not be verified', $invalid);
        assertSameValue(0, $fixture['services']['enrollments']->saveCalls);

        assertSameValue('', representativeEnrollmentPost($fixture['controller'], 'open', representativeEnrollmentContext()));
        assertSameValue(303, http_response_code());
        assertSameValue(1, $fixture['services']['enrollments']->saveCalls);
        $persisted = $fixture['services']['enrollments']->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId(44),
            new AcademicPeriodId(5),
        );
        assertSameValue(true, $persisted?->id() !== null);

        representativeEnrollmentPost($fixture['controller'], 'open', representativeEnrollmentContext());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $fixture['services']['enrollments']->saveCalls);
        deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
        deliveryAssertContains('Enrollment Draft is ready.', $fixture['controller']->index());
    });

    $runner->add('E011 every Enrollment POST rejects invalid CSRF before Application', function (): void {
        foreach ([
            'open',
            'updateRepresentativePersonal',
            'updateRepresentativeContact',
            'updateRepresentativeEmployment',
            'updateStudentPersonal',
            'updateBilling',
            'updateMedical',
            'updateTransport',
            'updateLeaveAlone',
        ] as $method) {
            $fixture = representativeEnrollmentDeliveryFixture();
            $html = representativeEnrollmentPost($fixture['controller'], $method, ['_csrf_token' => 'invalid']);
            assertSameValue(403, http_response_code(), $method);
            deliveryAssertContains('could not be verified', $html);
            assertSameValue(0, $fixture['services']['enrollments']->saveCalls, $method);
            assertSameValue(0, $fixture['services']['persons']->saveCalls(), $method);
            assertSameValue(0, $fixture['services']['representatives']->saveCalls(), $method);
        }
    });

    $runner->add('E011 pending acknowledgements and concurrent initialization failure stay safe', function (): void {
        $pending = representativeEnrollmentDeliveryFixture(acknowledgementsSatisfied: false);
        assertSameValue('', representativeEnrollmentPost($pending['controller'], 'open', representativeEnrollmentContext()));
        assertSameValue(303, http_response_code());
        assertSameValue(0, $pending['services']['enrollments']->saveCalls);

        $concurrent = representativeEnrollmentDeliveryFixture();
        $concurrent['services']['enrollments']->saveFailure = new RuntimeException('SQLSTATE 23000 secret row');
        $html = representativeEnrollmentPost($concurrent['controller'], 'open', representativeEnrollmentContext());
        assertSameValue(422, http_response_code());
        deliveryAssertContains('could not be confirmed', $html);
        assertSameValue(false, str_contains($html, 'SQLSTATE'));
        assertSameValue(false, str_contains($html, 'secret row'));
    });

    $runner->add('E011 Delivery rejects stale and unavailable context without enumeration', function (): void {
        foreach ([
            ['expected_family_id' => '88', 'expected_academic_period_id' => '5'],
            ['expected_family_id' => '77', 'expected_academic_period_id' => '6'],
        ] as $stale) {
            $fixture = representativeEnrollmentDeliveryFixture();
            $html = representativeEnrollmentPost(
                $fixture['controller'],
                'open',
                array_merge(representativeEnrollmentContext(), $stale),
            );
            assertSameValue(409, http_response_code());
            deliveryAssertContains('context changed', $html);
            assertSameValue(0, $fixture['services']['enrollments']->saveCalls);
        }

        $crossFamily = representativeEnrollmentDeliveryFixture();
        deliveryRequest('GET', '/representative/enrollment?student_id=999', ['student_id' => '999']);
        $crossHtml = $crossFamily['controller']->index();
        assertSameValue(403, http_response_code());
        assertSameValue(false, str_contains($crossHtml, '999'));

        $historical = representativeEnrollmentDeliveryFixture();
        $family = $historical['services']['families']->findById(new FamilyId(77));
        $family?->endStudentMembership(new FamilyStudentId(44), new \DateTimeImmutable('2026-08-22 00:00:00+00:00'));
        $historical['services']['families']->save($family);
        deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
        assertSameValue(403, representativeEnrollmentStatus($historical['controller']));
    });

    $runner->add('E011 Representative forms whitelist mutable fields validate and PRG safely', function (): void {
        $fixture = representativeEnrollmentDeliveryFixture();
        $personal = array_merge(representativeEnrollmentContext(), [
            'first_name' => 'Updated',
            'middle_name' => '',
            'first_surname' => 'Representative',
            'second_surname' => '',
            'birth_date' => '1991-02-03',
            'marital_status_id' => '4',
            'education_level_id' => '5',
            'document_number' => 'ATTACK',
            'sex_id' => '999',
            'status_id' => '999',
            'person_id' => '999',
        ]);
        representativeEnrollmentPost($fixture['controller'], 'updateRepresentativePersonal', $personal);
        assertSameValue(303, http_response_code());
        $person = $fixture['services']['persons']->findById(new \App\Person\Domain\ValueObject\PersonId(22));
        assertSameValue('DOC-REP', $person?->identification()?->documentNumber());
        assertSameValue(3, $person?->sexId());
        assertSameValue('Updated', $person?->personalName()->firstName());

        $invalidDate = array_merge($personal, ['birth_date' => '2026-02-30']);
        $invalidHtml = representativeEnrollmentPost($fixture['controller'], 'updateRepresentativePersonal', $invalidDate);
        assertSameValue(422, http_response_code());
        deliveryAssertContains('YYYY-MM-DD', $invalidHtml);
        deliveryAssertContains('value="2026-02-30"', $invalidHtml);

        representativeEnrollmentPost($fixture['controller'], 'updateRepresentativeContact', array_merge(
            representativeEnrollmentContext(),
            ['email' => 'updated@example.test', 'mobile_phone' => '', 'landline_phone' => 'free form ext. A'],
        ));
        assertSameValue(303, http_response_code());
        representativeEnrollmentPost($fixture['controller'], 'updateRepresentativeEmployment', array_merge(
            representativeEnrollmentContext(),
            ['occupation' => '', 'company_name' => '', 'position' => '', 'work_phone' => '', 'work_email' => ''],
        ));
        assertSameValue(303, http_response_code());
        assertSameValue(null, $fixture['services']['representatives']->findById(
            new \App\Representative\Domain\ValueObject\RepresentativeId(33),
        )?->employmentInformation());
    });

    $runner->add('E011 Student and annual section POSTs preserve server authority and sensitive-data boundaries', function (): void {
        $fixture = representativeEnrollmentDeliveryFixture();
        $fixture['services']['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));

        representativeEnrollmentPost($fixture['controller'], 'updateStudentPersonal', array_merge(
            representativeEnrollmentContext(),
            [
                'first_name' => 'Student', 'middle_name' => '', 'first_surname' => 'Updated',
                'second_surname' => '', 'birth_date' => '2015-02-03',
                'marital_status_id' => '4', 'education_level_id' => '5',
                'institutional_code' => 'ATTACK', 'status_id' => '999', 'person_id' => '999',
            ],
        ));
        assertSameValue(303, http_response_code());
        assertSameValue('STUDENT-44', $fixture['services']['students']->findById(
            new \App\Student\Domain\ValueObject\StudentId(44),
        )?->institutionalCode()->value());

        representativeEnrollmentPost($fixture['controller'], 'updateBilling', array_merge(
            representativeEnrollmentContext(),
            [
                'identification_type_id' => '2', 'identification_number' => 'BILL-1',
                'legal_name' => 'Billing Name', 'billing_address' => 'Billing Address',
                'billing_email' => 'billing@example.test', 'phone' => '555-0100',
                'enrollment_id' => '999',
            ],
        ));
        assertSameValue(303, http_response_code());
        $enrollment = $fixture['services']['enrollments']->findByStudentAndAcademicPeriod(
            new EnrollmentStudentId(44),
            new AcademicPeriodId(5),
        );
        assertSameValue('BILL-1', $enrollment?->billingInformation()?->identificationNumber());

        $medical = array_merge(representativeEnrollmentContext(), representativeEnrollmentMedicalValues());
        representativeEnrollmentPost($fixture['controller'], 'updateMedical', $medical);
        assertSameValue(303, http_response_code());
        assertSameValue(false, str_contains('/representative/enrollment?student_id=44', 'Medical secret'));

        $invalidMedical = array_merge($medical, [
            'has_medical_condition' => '1',
            'medical_condition_detail' => '<script>Medical secret</script>',
            'has_allergies' => '1',
            'allergy_detail' => '',
        ]);
        $invalidHtml = representativeEnrollmentPost($fixture['controller'], 'updateMedical', $invalidMedical);
        assertSameValue(422, http_response_code());
        deliveryAssertContains('Allergy detail is required', $invalidHtml);
        deliveryAssertContains('&lt;script&gt;Medical secret&lt;/script&gt;', $invalidHtml);
        assertSameValue(false, str_contains($invalidHtml, '<script>Medical secret</script>'));
        assertSameValue(null, $fixture['session']->get('_flash_representative_enrollment_error'));
    });

    $runner->add('E011 boolean sections require explicit Yes or No and accept both values', function (): void {
        $fixture = representativeEnrollmentDeliveryFixture();
        $fixture['services']['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));
        foreach ([
            ['updateTransport', 'requires_institutional_transport'],
            ['updateLeaveAlone', 'is_authorized_to_leave_alone'],
        ] as [$method, $field]) {
            foreach (['1', '0'] as $value) {
                representativeEnrollmentPost(
                    $fixture['controller'],
                    $method,
                    array_merge(representativeEnrollmentContext(), [$field => $value]),
                );
                assertSameValue(303, http_response_code());
            }
            $missing = representativeEnrollmentPost(
                $fixture['controller'],
                $method,
                representativeEnrollmentContext(),
            );
            assertSameValue(422, http_response_code());
            deliveryAssertContains('answered Yes or No', $missing);
            $invalid = representativeEnrollmentPost(
                $fixture['controller'],
                $method,
                array_merge(representativeEnrollmentContext(), [$field => 'yes']),
            );
            assertSameValue(422, http_response_code());
            deliveryAssertContains('answered Yes or No', $invalid);
        }
    });

    $runner->add('E011 Submitted Completed and Cancelled states are readonly in UI and Application', function (): void {
        foreach ([EnrollmentStatus::Submitted, EnrollmentStatus::Completed, EnrollmentStatus::Cancelled] as $status) {
            $fixture = representativeEnrollmentDeliveryFixture();
            $fixture['services']['enrollments']->seed(representativeEnrollmentPersistedState($status));
            deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
            $html = $fixture['controller']->index();
            deliveryAssertContains('Status: <strong>' . $status->value . '</strong>', $html);
            deliveryAssertContains('This Enrollment is read-only.', $html);
            assertSameValue(false, str_contains($html, 'Save Personal Information'));
            assertSameValue(false, str_contains($html, 'Start Enrollment Draft'));
            assertSameValue(false, str_contains($html, 'Submit Enrollment'));

            $manual = representativeEnrollmentPost(
                $fixture['controller'],
                'updateTransport',
                array_merge(representativeEnrollmentContext(), ['requires_institutional_transport' => '1']),
            );
            assertSameValue(409, http_response_code());
            deliveryAssertContains('no longer editable', $manual);
        }
    });

    $runner->add('E011 Delivery is escaped White Label mobile-first accessible and has no JavaScript autosave', function (): void {
        $fixture = representativeEnrollmentDeliveryFixture();
        $fixture['services']['resolveOrStart']->handle(new ResolveOrStartRepresentativeEnrollmentInput(77, 5, 44));
        representativeEnrollmentPost($fixture['controller'], 'open', representativeEnrollmentContext());
        deliveryRequest('GET', '/representative/enrollment?student_id=44', ['student_id' => '44']);
        $html = $fixture['controller']->index();
        foreach (['class="container', 'col-12', '<label', '<fieldset', '<legend', 'role="status"', 'Family Resources'] as $required) {
            deliveryAssertContains($required, $html);
        }
        deliveryAssertContains('Identification &lt;Type&gt;', $html);
        deliveryAssertContains('Marital &amp; Status', $html);
        deliveryAssertContains('Education &quot;Level&quot;', $html);

        $source = representativeEnrollmentNormalizedSource('app/Enrollment/Http/RepresentativeEnrollmentController.php')
            . representativeEnrollmentNormalizedSource('app/Enrollment/Http/RepresentativeEnrollmentInputMapper.php')
            . representativeEnrollmentNormalizedSource('resources/views/representative-portal/enrollment.php');
        foreach (['fetch(', 'XMLHttpRequest', 'debounce', 'beforeunload', 'sendBeacon', 'keepalive', 'SubmissionSnapshot', '->submit(', '->complete(', '->cancel(', '->reopen('] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        assertSameValue(0, preg_match('/ueant|unidad educativa antares/i', $source));
        assertSameValue(false, str_contains($source, 'name="enrollment_id"'));
        assertSameValue(false, str_contains($source, 'name="family_id"'));
        assertSameValue(false, str_contains($source, 'name="academic_period_id"'));
        assertSameValue(true, str_contains($source, 'htmlspecialchars'));
    });

    $runner->add('E011 production wiring resolves every Phase 2 boundary without a service locator', function (): void {
        $bootstrap = representativeEnrollmentNormalizedSource('bootstrap/app.php');
        foreach ([
            'EnrollmentRepository::class, PdoEnrollmentRepository::class',
            'AcademicPlacementReferenceProvider::class, PdoAcademicPlacementReferenceProvider::class',
            'RepresentativeEnrollmentPortalAuthorization::class',
            'GetRepresentativeEnrollmentPortalState::class',
            'ResolveOrStartRepresentativeEnrollment::class',
            'UpdateAuthenticatedRepresentativePersonalInformation::class',
            'UpdateAuthenticatedRepresentativeContactInformation::class',
            'UpdateAuthenticatedRepresentativeEmploymentInformation::class',
            'UpdateAuthorizedStudentPersonalInformation::class',
            'UpdateRepresentativeEnrollmentBillingInformation::class',
            'UpdateRepresentativeEnrollmentMedicalInformation::class',
            'UpdateRepresentativeEnrollmentTransportInformation::class',
            'UpdateRepresentativeEnrollmentLeaveAloneAuthorization::class',
            'RepresentativeEnrollmentController::class',
        ] as $binding) {
            assertSameValue(true, str_contains($bootstrap, $binding), $binding);
        }

        $controller = representativeEnrollmentNormalizedSource('app/Enrollment/Http/RepresentativeEnrollmentController.php');
        foreach (['new PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Repository', 'container()->', 'Session::'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
    });
}

/** @return array<string, mixed> */
function representativeEnrollmentDeliveryFixture(
    bool $acknowledgementsSatisfied = true,
    bool $periodActive = true,
    int $familyCount = 1,
    bool $representativeExists = true,
): array {
    $services = e011PortalFixture(
        $acknowledgementsSatisfied,
        $periodActive,
        true,
        $representativeExists,
        $familyCount,
    );
    $session = new FakeSessionManager();
    $options = new class implements PersonFormOptionsProvider {
        public function get(): PersonFormOptions
        {
            return new PersonFormOptions(
                [new PersonFormOption(2, 'ID', 'Identification <Type>')],
                [new PersonFormOption(3, 'SEX', 'Registered Sex')],
                [new PersonFormOption(4, 'MARITAL', 'Marital & Status')],
                [new PersonFormOption(5, 'EDUCATION', 'Education "Level"')],
                [],
            );
        }
    };
    $controller = new RepresentativeEnrollmentController(
        $services['state'],
        $services['resolveOrStart'],
        $services['personal'],
        $services['contact'],
        $services['employment'],
        $services['studentPersonal'],
        $services['billing'],
        $services['medical'],
        $services['transport'],
        $services['leave'],
        $options,
        e010AcademicReferences(),
        new FakeDeliveryCsrf(),
        $session,
        new RepresentativeEnrollmentInputMapper(),
    );

    return ['controller' => $controller, 'services' => $services, 'session' => $session];
}

/** @return array<string, string> */
function representativeEnrollmentContext(): array
{
    return [
        '_csrf_token' => 'delivery-csrf',
        'expected_family_id' => '77',
        'expected_academic_period_id' => '5',
        'student_id' => '44',
    ];
}

/** @return array<string, string> */
function representativeEnrollmentMedicalValues(): array
{
    return [
        'has_medical_condition' => '0', 'medical_condition_detail' => '',
        'has_allergies' => '0', 'allergy_detail' => '',
        'takes_permanent_medication' => '0', 'medication_name' => '',
        'requires_special_care' => '0', 'special_care_detail' => '',
        'has_medical_insurance' => '0', 'insurance_provider' => '',
        'pediatrician_name' => '', 'pediatrician_phone' => '', 'observations' => '',
    ];
}

/** @param array<string, mixed> $input */
function representativeEnrollmentPost(
    RepresentativeEnrollmentController $controller,
    string $method,
    array $input,
): string {
    deliveryRequest('POST', '/representative/enrollment', $input);

    return $controller->{$method}();
}

function representativeEnrollmentStatus(RepresentativeEnrollmentController $controller): int
{
    $controller->index();

    return (int) http_response_code();
}

function representativeEnrollmentPersistedState(EnrollmentStatus $status): Enrollment
{
    $hasSubmission = in_array($status, [EnrollmentStatus::Submitted, EnrollmentStatus::Completed], true);
    $submittedAt = $hasSubmission ? new \DateTimeImmutable('2026-08-21 13:00:00+00:00') : null;

    return Enrollment::reconstitute(
        new EnrollmentId(900 + array_search($status, EnrollmentStatus::cases(), true)),
        new EnrollmentStudentId(44),
        new EnrollmentFamilyId(77),
        new AcademicPeriodId(5),
        $status,
        null,
        null,
        null,
        null,
        false,
        $hasSubmission ? persistedSubmissionSnapshot() : null,
        new \DateTimeImmutable('2026-08-21 12:00:00+00:00'),
        $submittedAt,
        $status === EnrollmentStatus::Completed ? new \DateTimeImmutable('2026-08-21 14:00:00+00:00') : null,
        $status === EnrollmentStatus::Cancelled ? new \DateTimeImmutable('2026-08-21 13:00:00+00:00') : null,
    );
}

function representativeEnrollmentNormalizedSource(string $path): string
{
    return str_replace(
        ["\r\n", "\r"],
        "\n",
        (string) file_get_contents(dirname(__DIR__) . '/' . $path),
    );
}
