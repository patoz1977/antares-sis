<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Controllers\Controller;
use App\Enrollment\Application\AcademicInitialization\PreviewAcademicInitialization;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Http\Request;
use Throwable;

final class AcademicInitializationController extends Controller
{
    public function __construct(
        private readonly PreviewAcademicInitialization $preview,
        private readonly AcademicInitializationTemporaryFileStore $temporaryFiles,
        private readonly AcademicInitializationDeliverySession $workflow,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function index(): string
    {
        return $this->renderPage();
    }

    public function preview(): string
    {
        $request = new Request();
        $input = $request->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->renderPage(errorMessage: 'La solicitud no pudo verificarse.', status: 403);
        }
        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }

        $this->workflow->invalidateForNewPreview();
        if (!$this->containsOnly($input, ['_csrf_token', 'academic_period_code'])) {
            return $this->renderPage(errorMessage: 'La solicitud de preflight no es válida.', status: 422);
        }
        $academicPeriodCode = $this->scalar($input, 'academic_period_code');
        if ($academicPeriodCode === '') {
            return $this->renderPage(errorMessage: 'Indique el código del período académico.', status: 422);
        }

        $localPath = null;
        try {
            $upload = $request->files()['manifest'] ?? null;
            $localPath = $this->temporaryFiles->store(is_array($upload) ? $upload : []);
            $digest = hash_file('sha256', $localPath);
            if (!is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new AcademicInitializationUploadRejected(
                    'El manifest no pudo verificarse de forma segura.'
                );
            }

            $result = $this->preview->handle($localPath, $academicPeriodCode);
            $token = $result->isApplicable()
                ? $this->workflow->issuePreview(
                    $actorId,
                    $digest,
                    $result->stateDigest(),
                    $result->academicPeriodCode,
                )
                : null;

            return $this->renderPage(
                preview: $result->safeOutput(),
                previewToken: $token,
                academicPeriodCode: $result->academicPeriodCode,
                status: $result->isApplicable() ? 200 : 422,
            );
        } catch (AcademicInitializationUploadRejected $exception) {
            return $this->renderPage(errorMessage: $exception->getMessage(), status: 422);
        } catch (Throwable) {
            return $this->renderPage(
                errorMessage: 'El manifest no pudo procesarse de forma segura.',
                status: 500,
            );
        } finally {
            if (is_string($localPath)) {
                $this->temporaryFiles->delete($localPath);
            }
        }
    }

    public function result(): string
    {
        $actorId = $this->session->authenticatedUserId();
        if ($actorId === null) {
            return $this->plainError('La sesión administrativa no está disponible.', 403);
        }
        $result = $this->workflow->pullResult($actorId);
        http_response_code($result === null ? 404 : 200);
        header('Cache-Control: no-store');

        return $this->view('academic-initialization.result', [
            'title' => 'Resultado de inicialización académica',
            'result' => $result,
        ]);
    }

    /** @param array<string, mixed>|null $preview */
    private function renderPage(
        ?array $preview = null,
        ?string $previewToken = null,
        ?string $errorMessage = null,
        string $academicPeriodCode = '',
        int $status = 200,
    ): string {
        http_response_code($status);
        header('Cache-Control: no-store');

        return $this->view('academic-initialization.index', [
            'title' => 'Inicialización académica',
            'csrfToken' => $this->csrf->token(),
            'preview' => $preview,
            'previewToken' => $previewToken,
            'errorMessage' => $errorMessage,
            'academicPeriodCode' => $academicPeriodCode,
        ]);
    }

    /** @param array<mixed> $input @param list<string> $allowed */
    private function containsOnly(array $input, array $allowed): bool
    {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);
        sort($allowed, SORT_STRING);

        return $keys === $allowed;
    }

    /** @param array<mixed> $input */
    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function plainError(string $message, int $status): string
    {
        http_response_code($status);
        header('Cache-Control: no-store');

        return '<h1>Inicialización académica no disponible</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
}
